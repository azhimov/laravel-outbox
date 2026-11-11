<?php

declare(strict_types=1);

use Azhimov\Outbox\Facades\Outbox;
use Azhimov\Outbox\Testing\OutboxFake;
use Azhimov\Outbox\Tests\Fixtures\OrderPaid;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;

it('swaps the outbox for a fake that writes nothing', function (): void {
    $fake = Outbox::fake();

    Outbox::publish(topic: 'orders', type: 'order.paid', payload: ['order_id' => 1]);

    expect($fake)->toBeInstanceOf(OutboxFake::class)
        ->and($fake->published())->toHaveCount(1);
});

it('does not require a transaction, so it works with RefreshDatabase', function (): void {
    Outbox::fake();

    Outbox::publish(topic: 'orders', type: 'order.paid', payload: []);

    Outbox::assertPublished('order.paid');
});

it('asserts a message was published, optionally matching a callback', function (): void {
    Outbox::fake();

    Outbox::publishEvent(new OrderPaid(1001, '25.00'));

    Outbox::assertPublished('order.paid');
    Outbox::assertPublished('order.paid', fn ($m): bool => $m->payload['amount'] === '25.00' && $m->aggregateId === 'order-1001');
    Outbox::assertPublishedTimes('order.paid', 1);
    Outbox::assertNotPublished('order.refunded');

    expect(fn () => Outbox::assertPublished('order.paid', fn ($m): bool => $m->payload['amount'] === '99.00'))
        ->toThrow(AssertionFailedError::class)
        ->and(fn () => Outbox::assertPublished('order.refunded'))->toThrow(AssertionFailedError::class)
        ->and(fn () => Outbox::assertNotPublished('order.paid'))->toThrow(AssertionFailedError::class)
        ->and(fn () => Outbox::assertPublishedTimes('order.paid', 2))->toThrow(AssertionFailedError::class);
});

it('asserts nothing was published', function (): void {
    Outbox::fake();

    Outbox::assertNothingPublished();

    Outbox::publish(topic: 'orders', type: 'order.paid', payload: []);

    expect(fn () => Outbox::assertNothingPublished())->toThrow(AssertionFailedError::class);
});

it('numbers fake messages per aggregate like the real outbox', function (): void {
    $fake = Outbox::fake();

    Outbox::publish(topic: 'orders', type: 'a', payload: [], aggregateId: 'order-1');
    Outbox::publish(topic: 'orders', type: 'b', payload: [], aggregateId: 'order-1');
    Outbox::publish(topic: 'orders', type: 'c', payload: []);

    expect(array_map(fn ($m): ?int => $m->aggregateSeq, $fake->published()))->toBe([1, 2, null]);
});

it('validates messages like the real outbox', function (): void {
    Outbox::fake();

    Outbox::publish(topic: '', type: 'order.paid', payload: []);
})->throws(InvalidArgumentException::class);

it('really writes nothing to the database', function (): void {
    migrateOutboxTables();
    Outbox::fake();

    DB::transaction(fn () => Outbox::publish(topic: 'orders', type: 'order.paid', payload: []));

    expect(DB::table('outbox_messages')->count())->toBe(0);
});
