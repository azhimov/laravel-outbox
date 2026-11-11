<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Relay;

use Azhimov\Outbox\Contracts\Transport;
use Azhimov\Outbox\Support\MessageStatus;
use Azhimov\Outbox\Support\OutboxMessage;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Throwable;
use UnexpectedValueException;

/**
 * Moves messages from the outbox table to the transport.
 *
 * 1. claim(): in a short transaction, lock a batch with FOR UPDATE SKIP LOCKED (other
 *    workers skip those rows instead of waiting) and lease it to this worker: status
 *    processing, lease_owner = this worker's random token, lease_until = now + lease.
 * 2. deliver(): call the transport outside any transaction, then mark the message
 *    published only if this worker still owns the lease (compare-and-set on lease_owner).
 *
 * A worker that dies keeps nothing forever: once lease_until passes, another worker
 * claims the message again. That is why delivery is at-least-once.
 *
 * Ordering: a message of an aggregate is claimable only while every earlier message of
 * that aggregate (lower aggregate_seq) is published. So each aggregate has at most one
 * message in flight, a failed one blocks its aggregate until retried, and different
 * aggregates are still spread over all workers.
 */
final class Relay
{
    private readonly string $owner;

    private readonly Backoff $backoff;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly Transport $transport,
        private readonly RelayOptions $options,
        ?Backoff $backoff = null,
    ) {
        $this->owner = bin2hex(random_bytes(16));
        $this->backoff = $backoff ?? Backoff::from($options);
    }

    public function runOnce(): RelayResult
    {
        [$messages, $deadLettered] = $this->claimBatch();
        $result = new RelayResult(claimed: count($messages), deadLettered: $deadLettered);

        foreach ($messages as $message) {
            $result = $result->with($this->deliver($message));
        }

        return $result;
    }

    /**
     * @return list<OutboxMessage>
     */
    public function claim(): array
    {
        return $this->claimBatch()[0];
    }

    /**
     * @return array{0: list<OutboxMessage>, 1: int} claimed messages, messages dead-lettered on the way
     */
    private function claimBatch(): array
    {
        $now = self::now();

        [$ids, $deadLettered] = $this->connection->transaction(function () use ($now): array {
            $rows = $this->claimable($now)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($this->options->batchSize)
                ->lock('for update skip locked')
                ->get(['id', 'status', 'attempts']);

            $claim = [];
            $exhausted = [];

            foreach ($rows as $row) {
                $id = is_string($row->id) ? $row->id : throw new UnexpectedValueException('Outbox ids must be strings.');

                // A lease that ran out on the last allowed attempt: the worker died while
                // delivering. Claiming it again could kill the next worker the same way.
                $attempts = is_numeric($row->attempts) ? (int) $row->attempts : 0;

                if ($row->status === MessageStatus::Processing->value && $attempts >= $this->options->maxAttempts) {
                    $exhausted[] = $id;
                } else {
                    $claim[] = $id;
                }
            }

            if ($exhausted !== []) {
                $this->table()->whereIn('id', $exhausted)->update([
                    'status' => MessageStatus::Failed->value,
                    'lease_owner' => null,
                    'lease_until' => null,
                    'last_error' => "Lease expired after {$this->options->maxAttempts} attempts: the worker stopped while delivering.",
                ]);
            }

            if ($claim !== []) {
                $this->table()->whereIn('id', $claim)->increment('attempts', 1, [
                    'status' => MessageStatus::Processing->value,
                    'lease_owner' => $this->owner,
                    'lease_until' => $now + $this->options->leaseMs,
                ]);
            }

            return [$claim, count($exhausted)];
        });

        if ($ids === []) {
            return [[], $deadLettered];
        }

        $messages = array_values($this->table()
            ->whereIn('id', $ids)
            ->where('lease_owner', $this->owner)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): OutboxMessage => OutboxMessage::fromRow($row))
            ->all());

        return [$messages, $deadLettered];
    }

    public function deliver(OutboxMessage $message): DeliveryOutcome
    {
        try {
            $this->transport->publish($message);
        } catch (Throwable $e) {
            return $this->fail($message, $e);
        }

        $marked = $this->owned($message)->update([
            'status' => MessageStatus::Published->value,
            'published_at' => self::now(),
            'lease_owner' => null,
            'lease_until' => null,
        ]);

        return $marked === 1 ? DeliveryOutcome::Published : DeliveryOutcome::LeaseLost;
    }

    /**
     * Back to pending after a backoff, or to failed (the dead letter) on the last attempt.
     * $message->attempts already counts this delivery: it is incremented on claim.
     */
    private function fail(OutboxMessage $message, Throwable $error): DeliveryOutcome
    {
        $error = mb_substr($error::class.': '.$error->getMessage(), 0, 2_000);

        if ($message->attempts >= $this->options->maxAttempts) {
            $this->owned($message)->update([
                'status' => MessageStatus::Failed->value,
                'lease_owner' => null,
                'lease_until' => null,
                'last_error' => $error,
            ]);

            return DeliveryOutcome::DeadLettered;
        }

        $this->owned($message)->update([
            'status' => MessageStatus::Pending->value,
            'available_at' => self::now() + $this->backoff->delayMs($message->attempts),
            'lease_owner' => null,
            'lease_until' => null,
            'last_error' => $error,
        ]);

        return DeliveryOutcome::Failed;
    }

    /**
     * Pending and due, or leased by a worker whose lease has run out;
     * with ordering, also the head of its aggregate.
     */
    private function claimable(int $now): Builder
    {
        $query = $this->table()->where(function (Builder $query) use ($now): void {
            $query
                ->where(fn (Builder $q) => $q->where('status', MessageStatus::Pending->value)->where('available_at', '<=', $now))
                ->orWhere(fn (Builder $q) => $q->where('status', MessageStatus::Processing->value)->where('lease_until', '<', $now));
        });

        if (! $this->options->ordered) {
            return $query;
        }

        return $query->where(function (Builder $query): void {
            $query->whereNull('aggregate_id')->orWhereNotExists(function (Builder $earlier): void {
                $earlier->selectRaw('1')
                    ->from("{$this->table} as earlier")
                    ->whereColumn('earlier.aggregate_id', "{$this->table}.aggregate_id")
                    ->whereColumn('earlier.aggregate_seq', '<', "{$this->table}.aggregate_seq")
                    ->where('earlier.status', '!=', MessageStatus::Published->value);
            });
        });
    }

    /**
     * The message's row, but only while this worker still holds its lease.
     */
    private function owned(OutboxMessage $message): Builder
    {
        return $this->table()
            ->where('id', $message->id->value)
            ->where('status', MessageStatus::Processing->value)
            ->where('lease_owner', $this->owner);
    }

    private function table(): Builder
    {
        return $this->connection->table($this->table);
    }

    private static function now(): int
    {
        return Date::now()->getTimestampMs();
    }
}
