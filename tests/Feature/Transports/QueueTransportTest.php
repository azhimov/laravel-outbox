<?php

declare(strict_types=1);

use Azhimov\Outbox\Events\UnhandledOutboxMessage;
use Azhimov\Outbox\Jobs\DeliverOutboxMessage;
use Azhimov\Outbox\Support\CloudEvent;
use Azhimov\Outbox\Support\ReceivedMessage;
use Azhimov\Outbox\Tests\Fixtures\RecordingHandler;
use Azhimov\Outbox\Transports\QueueTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    RecordingHandler::$received = [];
});

transportContract(
    fn () => new QueueTransport(app('queue'), connection: null, queue: null, source: 'shop'),
    fn () => Queue::pushed(DeliverOutboxMessage::class)
        ->map(fn (DeliverOutboxMessage $job): CloudEvent => $job->event)
        ->values()
        ->all(),
);

it('pushes the job to the configured connection and queue', function (): void {
    (new QueueTransport(app('queue'), connection: 'sync-test', queue: 'outbox', source: 'shop'))->publish(outboxMessage());

    Queue::connection('sync-test')->assertPushedOn('outbox', DeliverOutboxMessage::class);
});

it('hands the received message to the handler mapped to its type', function (): void {
    config(['outbox.transports.queue.handlers' => ['order.paid' => RecordingHandler::class]]);

    $job = new DeliverOutboxMessage(CloudEvent::fromMessage(outboxMessage(), 'shop'));
    app()->call([$job, 'handle']);

    expect(RecordingHandler::$received)->toHaveCount(1);

    /** @var ReceivedMessage $message */
    $message = RecordingHandler::$received[0];
    expect($message->id)->toBe('0193a2c4-6f1e-7b3a-9c2d-1e2f3a4b5c6d')
        ->and($message->type)->toBe('order.paid')
        ->and($message->source)->toBe('shop')
        ->and($message->subject)->toBe('order-1001')
        ->and($message->topic)->toBe('orders')
        ->and($message->payload)->toBe(['order_id' => 1001, 'amount' => '25.00'])
        ->and($message->body)->toBe('{"order_id":1001,"amount":"25.00"}')
        ->and($message->headers)->toBe(['tenant' => 'demo']);
});

it('reports an unknown type with an event and a log line instead of failing', function (): void {
    Event::fake([UnhandledOutboxMessage::class]);
    Log::spy();

    $job = new DeliverOutboxMessage(CloudEvent::fromMessage(outboxMessage(['type' => 'order.unknown']), 'shop'));
    app()->call([$job, 'handle']);

    Event::assertDispatched(UnhandledOutboxMessage::class, fn (UnhandledOutboxMessage $e): bool => $e->message->type === 'order.unknown');
    Log::shouldHaveReceived('warning')->once();
});

it('fails loudly when a mapped handler cannot handle messages', function (): void {
    config(['outbox.transports.queue.handlers' => ['order.paid' => stdClass::class]]);

    $job = new DeliverOutboxMessage(CloudEvent::fromMessage(outboxMessage(), 'shop'));
    app()->call([$job, 'handle']);
})->throws(LogicException::class);

it('survives serialization onto a real queue payload', function (): void {
    $job = unserialize(serialize(new DeliverOutboxMessage(CloudEvent::fromMessage(outboxMessage(), 'shop'))));

    expect($job->event->data)->toBe('{"order_id":1001,"amount":"25.00"}');
});
