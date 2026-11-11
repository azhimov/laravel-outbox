<?php

declare(strict_types=1);

use Azhimov\Outbox\Events\AggregateBlocked;
use Azhimov\Outbox\Events\InboxDuplicateSkipped;
use Azhimov\Outbox\Events\LeaseLost;
use Azhimov\Outbox\Events\MessageFailed;
use Azhimov\Outbox\Events\MessagePublished;
use Azhimov\Outbox\Events\MessagePublishFailed;
use Azhimov\Outbox\Events\MessageStored;
use Azhimov\Outbox\Facades\Inbox;
use Azhimov\Outbox\Facades\Outbox;
use Azhimov\Outbox\Support\MessageId;
use Azhimov\Outbox\Tests\Fixtures\RecordingTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    migrateOutboxTables();
    migrateInboxTable();
    $this->freezeTime();
    $this->transport = new RecordingTransport;
});

it('announces a stored message only after the business transaction commits', function (): void {
    $seenInside = null;
    Event::listen(MessageStored::class, function () use (&$seenInside): void {
        $seenInside = DB::transactionLevel();
    });

    $id = DB::transaction(fn (): MessageId => Outbox::publish(topic: 'orders', type: 'order.paid', payload: [], aggregateId: 'order-1'));

    expect($seenInside)->toBe(0);

    Event::fake([MessageStored::class]);
    DB::transaction(fn () => Outbox::publish(topic: 'orders', type: 'order.paid', payload: []));
    Event::assertDispatched(MessageStored::class, fn (MessageStored $e): bool => $e->type === 'order.paid' && $e->aggregateId === null);

    expect($id)->toBeInstanceOf(MessageId::class);
});

it('announces nothing for a rolled back transaction', function (): void {
    Event::fake([MessageStored::class]);

    try {
        DB::transaction(function (): void {
            Outbox::publish(topic: 'orders', type: 'order.paid', payload: []);

            throw new RuntimeException('declined');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(MessageStored::class);
});

it('announces published messages', function (): void {
    Event::fake([MessagePublished::class]);
    $id = DB::transaction(fn (): MessageId => Outbox::publish(topic: 'orders', type: 'order.paid', payload: []));

    makeRelay($this->transport)->runOnce();

    Event::assertDispatched(MessagePublished::class, fn (MessagePublished $e): bool => $e->message->id->equals($id));
});

it('announces every failed attempt with the time of the next one', function (): void {
    Event::fake([MessagePublishFailed::class, MessageFailed::class]);
    DB::transaction(fn () => Outbox::publish(topic: 'orders', type: 'order.paid', payload: []));
    $this->transport->failTypes = ['order.paid'];

    makeRelay($this->transport, ['backoffBaseMs' => 1_000])->runOnce();

    Event::assertDispatched(MessagePublishFailed::class, fn (MessagePublishFailed $e): bool => $e->attempt === 1
        && $e->retryAt === now()->getTimestampMs() + 1_000
        && str_contains($e->error, 'broker rejected'));
    Event::assertNotDispatched(MessageFailed::class);
});

it('announces a dead-lettered message and the aggregate it blocks', function (): void {
    Event::fake([MessagePublishFailed::class, MessageFailed::class, AggregateBlocked::class]);
    DB::transaction(fn () => Outbox::publish(topic: 'orders', type: 'order.paid', payload: [], aggregateId: 'order-1'));
    $this->transport->failTypes = ['order.paid'];

    makeRelay($this->transport, ['maxAttempts' => 1])->runOnce();

    Event::assertDispatched(MessagePublishFailed::class, fn (MessagePublishFailed $e): bool => $e->retryAt === null);
    Event::assertDispatched(MessageFailed::class, fn (MessageFailed $e): bool => $e->type === 'order.paid' && str_contains($e->reason, 'broker rejected'));
    Event::assertDispatched(AggregateBlocked::class, fn (AggregateBlocked $e): bool => $e->aggregateId === 'order-1');
});

it('does not report a blocked aggregate when ordering is off or there is no aggregate', function (): void {
    Event::fake([AggregateBlocked::class]);
    DB::transaction(fn () => Outbox::publish(topic: 'orders', type: 'a', payload: [], aggregateId: 'order-1'));
    DB::transaction(fn () => Outbox::publish(topic: 'orders', type: 'b', payload: []));
    $this->transport->failTypes = ['a', 'b'];

    makeRelay($this->transport, ['maxAttempts' => 1, 'ordered' => false])->runOnce();

    Event::assertNotDispatched(AggregateBlocked::class);
});

it('announces a message dead-lettered after its lease expired on the last attempt', function (): void {
    Event::fake([MessageFailed::class, AggregateBlocked::class]);
    DB::transaction(fn () => Outbox::publish(topic: 'orders', type: 'order.paid', payload: [], aggregateId: 'order-1'));

    makeRelay(new RecordingTransport, ['maxAttempts' => 1])->claim();
    $this->travel(31)->seconds();
    makeRelay($this->transport, ['maxAttempts' => 1])->runOnce();

    Event::assertDispatched(MessageFailed::class, fn (MessageFailed $e): bool => str_contains($e->reason, 'Lease expired'));
    Event::assertDispatched(AggregateBlocked::class);
});

it('announces a lost lease', function (): void {
    Event::fake([LeaseLost::class]);
    DB::transaction(fn () => Outbox::publish(topic: 'orders', type: 'order.paid', payload: []));
    $relayA = makeRelay(new RecordingTransport);
    [$message] = $relayA->claim();
    $this->travel(31)->seconds();
    makeRelay($this->transport)->runOnce();

    $relayA->deliver($message);

    Event::assertDispatched(LeaseLost::class, fn (LeaseLost $e): bool => $e->message->id->equals($message->id));
});

it('announces a skipped inbox duplicate', function (): void {
    Event::fake([InboxDuplicateSkipped::class]);

    Inbox::handle('billing', 'msg-1', fn () => null);
    Event::assertNotDispatched(InboxDuplicateSkipped::class);

    Inbox::handle('billing', 'msg-1', fn () => null);
    Event::assertDispatched(InboxDuplicateSkipped::class, fn (InboxDuplicateSkipped $e): bool => $e->consumer === 'billing' && $e->messageId === 'msg-1');
});
