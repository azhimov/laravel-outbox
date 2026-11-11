<?php

declare(strict_types=1);

use Azhimov\Outbox\Facades\Outbox;
use Azhimov\Outbox\Support\MessageId;
use Azhimov\Outbox\Tests\Fixtures\RecordingTransport;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    migrateOutboxTables();
    $this->freezeTime();
    $this->transport = new RecordingTransport;
});

function publishFor(?string $aggregate, string $type): MessageId
{
    test()->travel(1)->milliseconds();

    return DB::transaction(fn (): MessageId => Outbox::publish(topic: 'orders', type: $type, payload: [], aggregateId: $aggregate));
}

it('delivers one message per aggregate at a time, in sequence order', function (): void {
    publishFor('order-1', 'placed');
    publishFor('order-1', 'paid');
    publishFor('order-1', 'shipped');
    $relay = makeRelay($this->transport);

    $relay->runOnce();
    expect($this->transport->types())->toBe(['placed']);

    $relay->runOnce();
    $relay->runOnce();
    expect($this->transport->types())->toBe(['placed', 'paid', 'shipped']);
});

it('runs different aggregates side by side in one batch', function (): void {
    publishFor('order-1', 'o1-placed');
    publishFor('order-2', 'o2-placed');
    publishFor('order-1', 'o1-paid');
    publishFor(null, 'audit');

    makeRelay($this->transport)->runOnce();

    expect($this->transport->types())->toBe(['o1-placed', 'o2-placed', 'audit']);
});

it('holds the next message while the previous one is in flight', function (): void {
    publishFor('order-1', 'placed');
    publishFor('order-1', 'paid');

    // Worker A has the head and is still talking to the broker.
    makeRelay(new RecordingTransport)->claim();

    expect(makeRelay($this->transport)->runOnce()->claimed)->toBe(0);
});

it('holds the next message while the previous one waits for a retry', function (): void {
    publishFor('order-1', 'placed');
    publishFor('order-1', 'paid');
    $this->transport->failTypes = ['placed'];
    $relay = makeRelay($this->transport);

    $relay->runOnce();
    $relay->runOnce();

    expect($this->transport->published)->toBe([]);

    $this->transport->failTypes = [];
    $this->travel(5)->seconds();
    $relay->runOnce();
    $relay->runOnce();

    expect($this->transport->types())->toBe(['placed', 'paid']);
});

it('blocks only its own aggregate when a message fails for good, until it is retried', function (): void {
    $placed = publishFor('order-1', 'placed');
    publishFor('order-1', 'paid');
    publishFor('order-2', 'other');
    $this->transport->failTypes = ['placed'];
    $relay = makeRelay($this->transport, ['maxAttempts' => 1]);

    $relay->runOnce();
    $relay->runOnce();

    expect(outboxRow($placed->value)->status)->toBe('failed')
        ->and($this->transport->types())->toBe(['other']);

    $this->transport->failTypes = [];
    $this->artisan('outbox:retry', ['id' => $placed->value])->assertSuccessful();
    $relay->runOnce();
    $relay->runOnce();

    expect($this->transport->types())->toBe(['other', 'placed', 'paid']);
});

it('ignores aggregates when ordering is off', function (): void {
    publishFor('order-1', 'placed');
    publishFor('order-1', 'paid');

    makeRelay($this->transport, ['ordered' => false])->runOnce();

    expect($this->transport->types())->toBe(['placed', 'paid']);
});

it('keeps per-aggregate order with several relay processes', function (): void {
    createDeliveriesTable();

    // 20 aggregates x 15 messages, interleaved like real traffic.
    foreach (range(1, 15) as $seq) {
        foreach (range(1, 20) as $aggregate) {
            DB::transaction(fn () => Outbox::publish(topic: 'orders', type: 'order.updated', payload: ['seq' => $seq], aggregateId: "order-{$aggregate}"));
        }
    }

    $workers = startRelayWorkers(count: 4, batchSize: 10);
    foreach ($workers as $worker) {
        $worker->wait();
        expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());
    }

    $deliveries = DB::table('test_deliveries')->orderBy('delivered_at')->orderBy('id')->get();

    expect($deliveries)->toHaveCount(300)
        ->and($deliveries->pluck('message_id')->unique())->toHaveCount(300)
        ->and($deliveries->pluck('worker')->unique()->count())->toBeGreaterThan(1);

    foreach ($deliveries->groupBy('aggregate_id') as $aggregate => $rows) {
        expect($rows->pluck('aggregate_seq')->map(fn ($s): int => (int) $s)->all())
            ->toBe(range(1, 15), "{$aggregate} was delivered out of order");
    }
})->skip(fn (): bool => ! usesRealDatabase(), 'needs a real server: SQLite has no row locks');
