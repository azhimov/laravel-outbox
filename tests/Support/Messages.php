<?php

declare(strict_types=1);

use Azhimov\Outbox\Support\MessageId;
use Azhimov\Outbox\Support\MessageStatus;
use Azhimov\Outbox\Support\OutboxMessage;

/**
 * An outbox message as the relay would hand it to a transport.
 *
 * @param  array<string, mixed>  $overrides
 */
function outboxMessage(array $overrides = []): OutboxMessage
{
    $body = $overrides['body'] ?? '{"order_id":1001,"amount":"25.00"}';

    return new OutboxMessage(
        id: $overrides['id'] ?? MessageId::fromString('0193a2c4-6f1e-7b3a-9c2d-1e2f3a4b5c6d'),
        topic: $overrides['topic'] ?? 'orders',
        type: $overrides['type'] ?? 'order.paid',
        body: $body,
        payload: json_decode($body, true),
        headers: $overrides['headers'] ?? ['tenant' => 'demo'],
        aggregateId: array_key_exists('aggregateId', $overrides) ? $overrides['aggregateId'] : 'order-1001',
        aggregateSeq: array_key_exists('aggregateId', $overrides) && $overrides['aggregateId'] === null ? null : ($overrides['aggregateSeq'] ?? 1),
        status: MessageStatus::Processing,
        attempts: 0,
        // 2026-11-12T10:00:00.123Z
        createdAt: $overrides['createdAt'] ?? 1794477600123,
    );
}
