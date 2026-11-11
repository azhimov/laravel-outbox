<?php

declare(strict_types=1);

use Azhimov\Outbox\Contracts\Outbox as OutboxContract;
use Azhimov\Outbox\Exceptions\NotInTransaction;
use Azhimov\Outbox\Facades\Outbox;
use Azhimov\Outbox\Support\MessageId;
use Azhimov\Outbox\Support\MessageStatus;
use Azhimov\Outbox\Support\OutboxMessage;
use Azhimov\Outbox\Tests\Fixtures\OrderPaid;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

beforeEach(fn () => migrateOutboxTables());

function storedMessage(MessageId $id): OutboxMessage
{
    $row = DB::table('outbox_messages')->where('id', $id->value)->first();

    expect($row)->not->toBeNull();

    return OutboxMessage::fromRow($row);
}

it('stores the message in the surrounding transaction', function (): void {
    $this->freezeTime();

    $id = DB::transaction(fn (): MessageId => Outbox::publish(
        topic: 'orders',
        type: 'order.paid',
        payload: ['order_id' => 1001, 'amount' => '25.00', 'note' => 'Алматы'],
        aggregateId: 'order-1001',
        headers: ['tenant' => 'demo'],
    ));

    $message = storedMessage($id);
    $row = DB::table('outbox_messages')->where('id', $id->value)->first();

    expect($message->topic)->toBe('orders')
        ->and($message->type)->toBe('order.paid')
        ->and($message->payload)->toBe(['order_id' => 1001, 'amount' => '25.00', 'note' => 'Алматы'])
        ->and($message->headers)->toBe(['tenant' => 'demo'])
        ->and($message->aggregateId)->toBe('order-1001')
        ->and($message->aggregateSeq)->toBe(1)
        ->and($message->status)->toBe(MessageStatus::Pending)
        ->and($message->attempts)->toBe(0)
        ->and($message->createdAt)->toBe(now()->getTimestampMs())
        ->and((int) $row->available_at)->toBe(now()->getTimestampMs());
});

it('stores nothing when the business transaction rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            Outbox::publish(topic: 'orders', type: 'order.paid', payload: [], aggregateId: 'order-1');

            throw new RuntimeException('payment declined');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('outbox_messages')->count())->toBe(0)
        ->and(DB::table('outbox_aggregates')->count())->toBe(0);
});

it('refuses to publish outside a transaction in strict mode', function (): void {
    expect(fn () => Outbox::publish(topic: 'orders', type: 'order.paid', payload: []))
        ->toThrow(NotInTransaction::class);

    expect(DB::table('outbox_messages')->count())->toBe(0);
});

it('publishes outside a transaction when strict mode is off', function (): void {
    config(['outbox.strict_transaction' => false]);

    $id = Outbox::publish(topic: 'orders', type: 'order.paid', payload: [], aggregateId: 'order-1');

    expect(storedMessage($id)->aggregateSeq)->toBe(1);
});

it('numbers messages per aggregate in publish order', function (): void {
    $seq = fn (string $aggregate): ?int => storedMessage(
        DB::transaction(fn (): MessageId => Outbox::publish(topic: 'orders', type: 'order.updated', payload: [], aggregateId: $aggregate)),
    )->aggregateSeq;

    expect([$seq('order-1'), $seq('order-1'), $seq('order-2'), $seq('order-1'), $seq('order-2')])
        ->toBe([1, 2, 1, 3, 2])
        ->and((int) DB::table('outbox_aggregates')->where('aggregate_id', 'order-1')->value('last_seq'))->toBe(3);
});

it('numbers several messages of one aggregate in one transaction', function (): void {
    $ids = DB::transaction(fn (): array => [
        Outbox::publish(topic: 'orders', type: 'order.paid', payload: [], aggregateId: 'order-1'),
        Outbox::publish(topic: 'orders', type: 'order.shipped', payload: [], aggregateId: 'order-1'),
    ]);

    expect(array_map(fn (MessageId $id): ?int => storedMessage($id)->aggregateSeq, $ids))->toBe([1, 2]);
});

it('leaves messages without an aggregate unnumbered', function (): void {
    $id = DB::transaction(fn (): MessageId => Outbox::publish(topic: 'audit', type: 'user.logged_in', payload: ['user' => 7]));

    expect(storedMessage($id)->aggregateId)->toBeNull()
        ->and(storedMessage($id)->aggregateSeq)->toBeNull()
        ->and(DB::table('outbox_aggregates')->count())->toBe(0);
});

it('keeps the payload bytes exactly as encoded', function (): void {
    $id = DB::transaction(fn (): MessageId => Outbox::publish(
        topic: 'orders',
        type: 'order.paid',
        payload: ['order_id' => 1001, 'amount' => '25.00', 'rate' => 1.0, 'url' => 'https://shop.example/o/1', 'city' => 'Алматы'],
    ));

    expect(DB::table('outbox_messages')->where('id', $id->value)->value('payload'))
        ->toBe('{"order_id":1001,"amount":"25.00","rate":1.0,"url":"https://shop.example/o/1","city":"Алматы"}');
});

it('stores empty headers as a JSON object', function (): void {
    $id = DB::transaction(fn (): MessageId => Outbox::publish(topic: 'orders', type: 'order.paid', payload: []));

    expect(json_decode((string) DB::table('outbox_messages')->where('id', $id->value)->value('headers')))
        ->toBeInstanceOf(stdClass::class);
});

it('publishes an event object', function (): void {
    $id = DB::transaction(fn (): MessageId => Outbox::publishEvent(new OrderPaid(1001, '25.00')));

    $message = storedMessage($id);

    expect($message->topic)->toBe('orders')
        ->and($message->type)->toBe('order.paid')
        ->and($message->payload)->toBe(['order_id' => 1001, 'amount' => '25.00'])
        ->and($message->aggregateId)->toBe('order-1001');
});

it('rejects invalid messages', function (array $args): void {
    DB::transaction(fn () => Outbox::publish(...$args));
})->throws(InvalidArgumentException::class)->with([
    'empty topic' => [['topic' => '', 'type' => 'order.paid', 'payload' => []]],
    'empty type' => [['topic' => 'orders', 'type' => ' ', 'payload' => []]],
    'empty aggregate' => [['topic' => 'orders', 'type' => 'order.paid', 'payload' => [], 'aggregateId' => '']],
    'non-string header' => [['topic' => 'orders', 'type' => 'order.paid', 'payload' => [], 'headers' => ['retries' => 3]]],
    'payload not encodable' => [['topic' => 'orders', 'type' => 'order.paid', 'payload' => ['bad' => NAN]]],
]);

it('writes on the configured outbox connection', function (): void {
    config(['database.connections.outbox_test' => config('database.connections.'.config('database.default'))]);
    config(['outbox.connection' => 'outbox_test']);
    app()->forgetInstance(OutboxContract::class);
    Outbox::clearResolvedInstances();

    // A transaction on the default connection is not a transaction on the outbox connection.
    expect(fn () => DB::transaction(fn () => Outbox::publish(topic: 'orders', type: 'order.paid', payload: [])))
        ->toThrow(NotInTransaction::class);
})->skip(fn (): bool => ! usesRealDatabase(), 'in-memory SQLite connections do not share data');

it('serializes writers of one aggregate in commit order', function (): void {
    // A second process publishes for order-1 and holds its transaction for 1.5 s.
    $worker = new Process(
        ['php', __DIR__.'/../Support/bin/worker.php', 'publish-and-hold', 'order-1', '1500'],
        env: getenv(),
    );
    $worker->start();
    $worker->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'published'));

    // This transaction started later, but must wait for the worker's commit before it gets a number.
    $started = microtime(true);
    $id = DB::transaction(fn (): MessageId => Outbox::publish(topic: 'orders', type: 'order.shipped', payload: [], aggregateId: 'order-1'));
    $waitedMs = (microtime(true) - $started) * 1000;

    $worker->wait();

    expect($worker->getOutput())->toContain('published 1')->toContain('committed')
        ->and(storedMessage($id)->aggregateSeq)->toBe(2)
        ->and($waitedMs)->toBeGreaterThan(800.0);
})->skip(fn (): bool => ! usesRealDatabase(), 'needs a real server: SQLite has no row locks');

it('reserves header names used by the transports', function (string $name): void {
    DB::transaction(fn () => Outbox::publish(topic: 'orders', type: 'order.paid', payload: [], headers: [$name => 'x']));
})->throws(InvalidArgumentException::class, 'reserved')->with(['ce_id', 'CE_type', 'data', 'content-type']);
