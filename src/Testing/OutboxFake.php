<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Testing;

use Azhimov\Outbox\Contracts\Outbox;
use Azhimov\Outbox\Contracts\ShouldBeOutboxed;
use Azhimov\Outbox\Support\MessageId;
use Azhimov\Outbox\Support\MessageStatus;
use Azhimov\Outbox\Support\NewMessage;
use Azhimov\Outbox\Support\OutboxMessage;
use Closure;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Records messages in memory instead of the database and does not require a transaction,
 * so application tests that use RefreshDatabase can assert what would be published.
 */
final class OutboxFake implements Outbox
{
    /** @var list<OutboxMessage> */
    private array $messages = [];

    /** @var array<string, int> */
    private array $sequences = [];

    public function publish(
        string $topic,
        string $type,
        array $payload,
        ?string $aggregateId = null,
        array $headers = [],
    ): MessageId {
        $message = new NewMessage($topic, $type, $payload, $aggregateId, $headers);
        $id = MessageId::generate();

        $seq = null;
        if ($aggregateId !== null) {
            $seq = $this->sequences[$aggregateId] = ($this->sequences[$aggregateId] ?? 0) + 1;
        }

        $this->messages[] = new OutboxMessage(
            id: $id,
            topic: $message->topic,
            type: $message->type,
            payload: $message->payload,
            headers: $message->headers(),
            aggregateId: $aggregateId,
            aggregateSeq: $seq,
            status: MessageStatus::Pending,
            attempts: 0,
            createdAt: Date::now()->getTimestampMs(),
        );

        return $id;
    }

    public function publishEvent(ShouldBeOutboxed $event): MessageId
    {
        return $this->publish($event->topic(), $event->type(), $event->payload(), $event->aggregateId());
    }

    /**
     * @param  (Closure(OutboxMessage): bool)|null  $callback
     * @return list<OutboxMessage>
     */
    public function published(?string $type = null, ?Closure $callback = null): array
    {
        return array_values(array_filter(
            $this->messages,
            fn (OutboxMessage $m): bool => ($type === null || $m->type === $type) && ($callback === null || $callback($m)),
        ));
    }

    /**
     * @param  (Closure(OutboxMessage): bool)|null  $callback
     */
    public function assertPublished(string $type, ?Closure $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->published($type, $callback),
            "The expected [{$type}] outbox message was not published.",
        );
    }

    public function assertPublishedTimes(string $type, int $times): void
    {
        $count = count($this->published($type));

        PHPUnit::assertSame($times, $count, "The [{$type}] outbox message was published {$count} times instead of {$times}.");
    }

    /**
     * @param  (Closure(OutboxMessage): bool)|null  $callback
     */
    public function assertNotPublished(string $type, ?Closure $callback = null): void
    {
        PHPUnit::assertEmpty(
            $this->published($type, $callback),
            "The unexpected [{$type}] outbox message was published.",
        );
    }

    public function assertNothingPublished(): void
    {
        $count = count($this->messages);

        PHPUnit::assertSame(0, $count, "{$count} unexpected outbox messages were published.");
    }
}
