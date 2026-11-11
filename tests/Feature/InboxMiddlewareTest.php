<?php

declare(strict_types=1);

use Azhimov\Outbox\Inbox\DeduplicateWithInbox;
use Azhimov\Outbox\Jobs\DeliverOutboxMessage;
use Azhimov\Outbox\Support\CloudEvent;
use Azhimov\Outbox\Tests\Fixtures\RecordingHandler;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    migrateInboxTable();
    RecordingHandler::$received = [];
    config(['outbox.transports.queue.handlers' => ['order.paid' => RecordingHandler::class]]);
});

function deliveryJob(): DeliverOutboxMessage
{
    return new DeliverOutboxMessage(CloudEvent::fromMessage(outboxMessage(), 'shop'));
}

function runThroughMiddleware(object $job, DeduplicateWithInbox $middleware): void
{
    $middleware->handle($job, fn (object $job) => app()->call([$job, 'handle']));
}

it('skips a delivery job whose message was already handled by this consumer', function (): void {
    $middleware = new DeduplicateWithInbox('billing');

    runThroughMiddleware(deliveryJob(), $middleware);
    runThroughMiddleware(deliveryJob(), $middleware);

    expect(RecordingHandler::$received)->toHaveCount(1)
        ->and(DB::table('inbox_messages')->where('consumer', 'billing')->count())->toBe(1);
});

it('takes the consumer from the job, then from config', function (): void {
    config(['outbox.inbox.consumer' => 'from-config']);
    $job = deliveryJob();

    runThroughMiddleware($job, new DeduplicateWithInbox);
    expect(DB::table('inbox_messages')->value('consumer'))->toBe('from-config');

    DB::table('inbox_messages')->delete();
    $job->inboxConsumer = 'from-job';
    runThroughMiddleware($job, new DeduplicateWithInbox);
    expect(DB::table('inbox_messages')->value('consumer'))->toBe('from-job');
});

it('reads the message id from jobs that provide one', function (): void {
    $job = new class
    {
        public int $runs = 0;

        public function outboxMessageId(): string
        {
            return 'external-42';
        }

        public function handle(): void
        {
            $this->runs++;
        }
    };

    runThroughMiddleware($job, new DeduplicateWithInbox('billing'));
    runThroughMiddleware($job, new DeduplicateWithInbox('billing'));

    expect($job->runs)->toBe(1);
});

it('fails for a job without a message id', function (): void {
    runThroughMiddleware(new class
    {
        public function handle(): void {}
    }, new DeduplicateWithInbox('billing'));
})->throws(LogicException::class);

it('deduplicates the queue transport when enabled in config', function (): void {
    config(['outbox.transports.queue.deduplicate' => true, 'outbox.inbox.consumer' => 'shop']);

    expect(deliveryJob()->middleware())->toHaveCount(1)
        ->and(deliveryJob()->middleware()[0])->toBeInstanceOf(DeduplicateWithInbox::class);

    config(['outbox.transports.queue.deduplicate' => false]);
    expect(deliveryJob()->middleware())->toBe([]);
});
