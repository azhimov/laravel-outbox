<?php

declare(strict_types=1);

use Azhimov\Outbox\Support\MessageId;
use Azhimov\Outbox\Support\MessageStatus;
use Azhimov\Outbox\Support\OutboxMessage;

function messageRow(array $overrides = []): array
{
    return array_merge([
        'id' => '0193a2c4-6f1e-7b3a-9c2d-1e2f3a4b5c6d',
        'topic' => 'orders',
        'type' => 'order.paid',
        'aggregate_id' => 'order-1001',
        'aggregate_seq' => 3,
        'payload' => '{"order_id":1001,"amount":"25.00"}',
        'headers' => '{"tenant":"demo"}',
        'status' => 'processing',
        'attempts' => 2,
        'created_at' => 1794398400123,
    ], $overrides);
}

it('rehydrates a database row', function (): void {
    $message = OutboxMessage::fromRow(messageRow());

    expect($message->id->equals(MessageId::fromString('0193a2c4-6f1e-7b3a-9c2d-1e2f3a4b5c6d')))->toBeTrue()
        ->and($message->topic)->toBe('orders')
        ->and($message->type)->toBe('order.paid')
        ->and($message->aggregateId)->toBe('order-1001')
        ->and($message->aggregateSeq)->toBe(3)
        ->and($message->payload)->toBe(['order_id' => 1001, 'amount' => '25.00'])
        ->and($message->headers)->toBe(['tenant' => 'demo'])
        ->and($message->status)->toBe(MessageStatus::Processing)
        ->and($message->attempts)->toBe(2)
        ->and($message->createdAt)->toBe(1794398400123);
});

it('accepts rows from drivers that return numbers as strings and objects', function (): void {
    $message = OutboxMessage::fromRow((object) messageRow([
        'aggregate_seq' => '3',
        'attempts' => '2',
        'created_at' => '1794398400123',
    ]));

    expect($message->aggregateSeq)->toBe(3)
        ->and($message->attempts)->toBe(2)
        ->and($message->createdAt)->toBe(1794398400123);
});

it('rehydrates a message without an aggregate', function (): void {
    $message = OutboxMessage::fromRow(messageRow(['aggregate_id' => null, 'aggregate_seq' => null, 'headers' => '{}']));

    expect($message->aggregateId)->toBeNull()
        ->and($message->aggregateSeq)->toBeNull()
        ->and($message->headers)->toBe([]);
});

it('refuses malformed rows', function (array $overrides): void {
    OutboxMessage::fromRow(messageRow($overrides));
})->throws(UnexpectedValueException::class)->with([
    'missing type' => [['type' => null]],
    'payload not json' => [['payload' => '{oops']],
    'payload a scalar' => [['payload' => '42']],
    'headers not a string map' => [['headers' => '{"tenant":["a"]}']],
    'unknown status' => [['status' => 'lost']],
    'negative attempts' => [['attempts' => -1]],
    'non-numeric seq' => [['aggregate_seq' => 'x']],
    'seq without aggregate' => [['aggregate_id' => null]],
]);
