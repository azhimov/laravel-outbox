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
 */
final class Relay
{
    private readonly string $owner;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly Transport $transport,
        private readonly RelayOptions $options,
    ) {
        $this->owner = bin2hex(random_bytes(16));
    }

    public function runOnce(): RelayResult
    {
        $messages = $this->claim();
        $result = new RelayResult(claimed: count($messages));

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
        $now = self::now();

        $ids = $this->connection->transaction(function () use ($now): array {
            $ids = $this->claimable($now)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($this->options->batchSize)
                ->lock('for update skip locked')
                ->pluck('id')
                ->map(fn (mixed $id): string => is_string($id) ? $id : throw new UnexpectedValueException('Outbox ids must be strings.'))
                ->values()
                ->all();

            if ($ids === []) {
                return [];
            }

            $this->table()->whereIn('id', $ids)->increment('attempts', 1, [
                'status' => MessageStatus::Processing->value,
                'lease_owner' => $this->owner,
                'lease_until' => $now + $this->options->leaseMs,
            ]);

            return $ids;
        });

        if ($ids === []) {
            return [];
        }

        return array_values($this->table()
            ->whereIn('id', $ids)
            ->where('lease_owner', $this->owner)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): OutboxMessage => OutboxMessage::fromRow($row))
            ->all());
    }

    public function deliver(OutboxMessage $message): DeliveryOutcome
    {
        try {
            $this->transport->publish($message);
        } catch (Throwable $e) {
            $this->release($message, $e);

            return DeliveryOutcome::Failed;
        }

        $marked = $this->owned($message)->update([
            'status' => MessageStatus::Published->value,
            'published_at' => self::now(),
            'lease_owner' => null,
            'lease_until' => null,
        ]);

        return $marked === 1 ? DeliveryOutcome::Published : DeliveryOutcome::LeaseLost;
    }

    private function release(OutboxMessage $message, Throwable $error): void
    {
        $this->owned($message)->update([
            'status' => MessageStatus::Pending->value,
            'available_at' => self::now(),
            'lease_owner' => null,
            'lease_until' => null,
            'last_error' => mb_substr($error::class.': '.$error->getMessage(), 0, 2_000),
        ]);
    }

    /**
     * Pending and due, or leased by a worker whose lease has run out.
     */
    private function claimable(int $now): Builder
    {
        return $this->table()->where(function (Builder $query) use ($now): void {
            $query
                ->where(fn (Builder $q) => $q->where('status', MessageStatus::Pending->value)->where('available_at', '<=', $now))
                ->orWhere(fn (Builder $q) => $q->where('status', MessageStatus::Processing->value)->where('lease_until', '<', $now));
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
