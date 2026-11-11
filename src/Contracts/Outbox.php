<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Contracts;

use Azhimov\Outbox\Support\MessageId;

interface Outbox
{
    /**
     * Stores a message for the relay. Must be called inside the transaction of the
     * business change, on the outbox connection, so both commit or roll back together.
     *
     * @param  array<array-key, mixed>  $payload  JSON-encodable data
     * @param  array<string, string>  $headers
     * @param  string|null  $aggregateId  messages of one aggregate are delivered in commit order
     */
    public function publish(
        string $topic,
        string $type,
        array $payload,
        ?string $aggregateId = null,
        array $headers = [],
    ): MessageId;

    public function publishEvent(ShouldBeOutboxed $event): MessageId;
}
