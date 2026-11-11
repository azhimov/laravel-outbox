<?php

declare(strict_types=1);

use Azhimov\Outbox\Facades\Outbox;
use Azhimov\Outbox\Relay\DeliveryOutcome;
use Azhimov\Outbox\Support\MessageId;
use Azhimov\Outbox\Support\OutboxMessage;
use Azhimov\Outbox\Tests\Fixtures\RecordingTransport;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    migrateOutboxTables();
    $this->freezeTime();
    $this->transport = new RecordingTransport;
});

function publishMessage(string $type = 'order.paid', ?string $aggregate = null): MessageId
{
    return DB::transaction(fn (): MessageId => Outbox::publish(topic: 'orders', type: $type, payload: ['type' => $type], aggregateId: $aggregate));
}

it('publishes pending messages and marks them published', function (): void {
    $id = publishMessage();

    $result = makeRelay($this->transport)->runOnce();

    $row = outboxRow($id->value);
    expect($result->claimed)->toBe(1)
        ->and($result->published)->toBe(1)
        ->and($this->transport->published[0]->id->equals($id))->toBeTrue()
        ->and($row->status)->toBe('published')
        ->and((int) $row->published_at)->toBe(now()->getTimestampMs())
        ->and($row->lease_owner)->toBeNull()
        ->and($row->lease_until)->toBeNull()
        ->and((int) $row->attempts)->toBe(1);
});

it('does nothing when the outbox is empty', function (): void {
    $result = makeRelay($this->transport)->runOnce();

    expect($result->claimed)->toBe(0)
        ->and($this->transport->published)->toBe([]);
});

it('publishes in creation order and respects the batch size', function (): void {
    foreach (['a', 'b', 'c', 'd', 'e'] as $type) {
        publishMessage($type);
        $this->travel(1)->milliseconds();
    }

    $relay = makeRelay($this->transport, ['batchSize' => 2]);

    expect($relay->runOnce()->claimed)->toBe(2)
        ->and($this->transport->types())->toBe(['a', 'b']);

    $relay->runOnce();
    $relay->runOnce();

    expect($this->transport->types())->toBe(['a', 'b', 'c', 'd', 'e'])
        ->and($relay->runOnce()->claimed)->toBe(0);
});

it('does not publish a message twice', function (): void {
    publishMessage();
    $relay = makeRelay($this->transport);

    $relay->runOnce();
    $relay->runOnce();

    expect($this->transport->published)->toHaveCount(1);
});

it('leaves messages that are not available yet', function (): void {
    $id = publishMessage();
    DB::table('outbox_messages')->where('id', $id->value)->update(['available_at' => now()->addMinute()->getTimestampMs()]);

    expect(makeRelay($this->transport)->runOnce()->claimed)->toBe(0);

    $this->travel(61)->seconds();

    expect(makeRelay($this->transport)->runOnce()->published)->toBe(1);
});

it('leases claimed messages to one worker until the lease expires', function (): void {
    $id = publishMessage();

    // Worker A claims, then stalls inside the broker call.
    $stalled = new RecordingTransport;
    $stalled->during = function (OutboxMessage $m) use ($id): void {
        $row = outboxRow($id->value);

        expect($row->status)->toBe('processing')
            ->and((int) $row->lease_until)->toBe(now()->getTimestampMs() + 30_000)
            ->and(strlen((string) $row->lease_owner))->toBe(32);

        // Worker B sees nothing while A holds the lease...
        expect(makeRelay(test()->transport)->runOnce()->claimed)->toBe(0);
    };

    makeRelay($stalled)->runOnce();

    expect($this->transport->published)->toBe([]);
});

it('takes over a message whose lease expired', function (): void {
    $id = publishMessage();
    $relayA = makeRelay(new RecordingTransport);
    $claimed = $relayA->claim();

    $this->travel(31)->seconds();

    $result = makeRelay($this->transport)->runOnce();

    expect($claimed)->toHaveCount(1)
        ->and($result->published)->toBe(1)
        ->and((int) outboxRow($id->value)->attempts)->toBe(2);
});

it('does not let a late worker overwrite a message it no longer leases', function (): void {
    $id = publishMessage();
    $late = new RecordingTransport;
    $relayA = makeRelay($late);
    [$message] = $relayA->claim();

    // A's lease runs out, B takes over and publishes.
    $this->travel(31)->seconds();
    makeRelay($this->transport)->runOnce();
    $publishedAt = (int) outboxRow($id->value)->published_at;

    // A finally comes back: the broker call already happened (at-least-once), the mark must not.
    $this->travel(5)->seconds();
    $outcome = $relayA->deliver($message);

    expect($outcome)->toBe(DeliveryOutcome::LeaseLost)
        ->and($late->published)->toHaveCount(1)
        ->and(outboxRow($id->value)->status)->toBe('published')
        ->and((int) outboxRow($id->value)->published_at)->toBe($publishedAt);
});

it('puts a message back when the transport fails', function (): void {
    $id = publishMessage('order.paid');
    $this->transport->failTypes = ['order.paid'];

    $result = makeRelay($this->transport)->runOnce();

    $row = outboxRow($id->value);
    expect($result->published)->toBe(0)
        ->and($result->failed)->toBe(1)
        ->and($row->status)->toBe('pending')
        ->and($row->lease_owner)->toBeNull()
        ->and($row->last_error)->toContain('broker rejected order.paid');
});

it('keeps publishing the rest of the batch after one failure', function (): void {
    publishMessage('bad');
    $this->travel(1)->milliseconds();
    publishMessage('good');
    $this->transport->failTypes = ['bad'];

    makeRelay($this->transport)->runOnce();

    expect($this->transport->types())->toBe(['good']);
});

it('validates relay options', function (array $options): void {
    makeRelay($this->transport, $options);
})->throws(InvalidArgumentException::class)->with([
    'batch 0' => [['batchSize' => 0]],
    'short lease' => [['leaseMs' => 500]],
    'no attempts' => [['maxAttempts' => 0]],
]);
