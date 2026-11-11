<?php

declare(strict_types=1);

use Azhimov\Outbox\Support\CloudEvent;

it('maps a message to CloudEvents 1.0 attributes', function (): void {
    $event = CloudEvent::fromMessage(outboxMessage(), source: 'shop');

    expect($event->attributes)->toBe([
        'specversion' => '1.0',
        'id' => '0193a2c4-6f1e-7b3a-9c2d-1e2f3a4b5c6d',
        'type' => 'order.paid',
        'source' => 'shop',
        'subject' => 'order-1001',
        'time' => '2026-11-12T10:00:00.123Z',
        'datacontenttype' => 'application/json',
    ])->and($event->data)->toBe('{"order_id":1001,"amount":"25.00"}')
        ->and($event->headers)->toBe(['tenant' => 'demo'])
        ->and($event->topic)->toBe('orders');
});

it('omits the subject of a message without an aggregate', function (): void {
    $event = CloudEvent::fromMessage(outboxMessage(['aggregateId' => null]), source: 'shop');

    expect($event->attributes)->not->toHaveKey('subject');
});

it('prefixes attributes for transports that carry them as headers', function (): void {
    $event = CloudEvent::fromMessage(outboxMessage(), source: 'shop');

    expect($event->prefixedAttributes())->toHaveKeys(['ce_specversion', 'ce_id', 'ce_type', 'ce_source', 'ce_subject', 'ce_time'])
        ->and($event->prefixedAttributes())->not->toHaveKey('ce_datacontenttype');
});

it('reads attributes back from prefixed headers', function (): void {
    $sent = CloudEvent::fromMessage(outboxMessage(), source: 'shop');

    $received = CloudEvent::fromPrefixed('orders', [...$sent->prefixedAttributes(), 'tenant' => 'demo'], $sent->data);

    expect($received->attributes)->toBe($sent->attributes)
        ->and($received->headers)->toBe(['tenant' => 'demo']);
});

it('refuses an empty source', function (): void {
    CloudEvent::fromMessage(outboxMessage(), source: '');
})->throws(InvalidArgumentException::class);
