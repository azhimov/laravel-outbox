<?php

declare(strict_types=1);

namespace Azhimov\Outbox;

use Azhimov\Outbox\Contracts\Outbox;
use Azhimov\Outbox\Contracts\ShouldBeOutboxed;
use Azhimov\Outbox\Exceptions\NotInTransaction;
use Azhimov\Outbox\Support\AggregateSequence;
use Azhimov\Outbox\Support\MessageId;
use Azhimov\Outbox\Support\MessageStatus;
use Azhimov\Outbox\Support\NewMessage;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Date;

final readonly class DatabaseOutbox implements Outbox
{
    public function __construct(
        private Connection $connection,
        private string $table,
        private string $aggregatesTable,
        private bool $strictTransaction,
    ) {}

    public function publish(
        string $topic,
        string $type,
        array $payload,
        ?string $aggregateId = null,
        array $headers = [],
    ): MessageId {
        $message = new NewMessage($topic, $type, $payload, $aggregateId, $headers);

        if ($this->connection->transactionLevel() > 0) {
            return $this->store($message);
        }

        if ($this->strictTransaction) {
            throw NotInTransaction::on($this->connection->getName() ?? 'default');
        }

        // Non-strict: no business transaction to join, but the counter and the row
        // still have to be written together.
        return $this->connection->transaction(fn (): MessageId => $this->store($message));
    }

    public function publishEvent(ShouldBeOutboxed $event): MessageId
    {
        return $this->publish($event->topic(), $event->type(), $event->payload(), $event->aggregateId());
    }

    private function store(NewMessage $message): MessageId
    {
        $id = MessageId::generate();
        $now = Date::now()->getTimestampMs();

        $this->connection->table($this->table)->insert([
            'id' => $id->value,
            'topic' => $message->topic,
            'type' => $message->type,
            'aggregate_id' => $message->aggregateId,
            'aggregate_seq' => $message->aggregateId === null
                ? null
                : AggregateSequence::next($this->connection, $this->aggregatesTable, $message->aggregateId),
            'payload' => $message->encodedPayload,
            'headers' => $message->encodedHeaders,
            'status' => MessageStatus::Pending->value,
            'attempts' => 0,
            'available_at' => $now,
            'created_at' => $now,
        ]);

        return $id;
    }
}
