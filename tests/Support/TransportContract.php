<?php

declare(strict_types=1);

use Azhimov\Outbox\Contracts\Transport;
use Azhimov\Outbox\Support\CloudEvent;
use Azhimov\Outbox\Support\MessageId;

/**
 * The behaviour every transport must have. Each transport test calls this with
 * a factory for the transport and a reader that returns what reached the other side.
 *
 * @param  Closure(): Transport  $transport
 * @param  Closure(): list<CloudEvent>  $delivered
 */
function transportContract(Closure $transport, Closure $delivered): void
{
    it('delivers the payload bytes unchanged', function () use ($transport, $delivered): void {
        $body = '{"order_id":1001,"amount":"25.00","rate":1.0,"city":"Алматы","url":"https://shop.example/o/1"}';

        $transport()->publish(outboxMessage(['body' => $body]));

        expect($delivered())->toHaveCount(1)
            ->and($delivered()[0]->data)->toBe($body);
    });

    it('delivers CloudEvents attributes, headers and the topic', function () use ($transport, $delivered): void {
        $transport()->publish(outboxMessage());

        $event = $delivered()[0];

        expect($event->attributes['specversion'])->toBe('1.0')
            ->and($event->attributes['id'])->toBe('0193a2c4-6f1e-7b3a-9c2d-1e2f3a4b5c6d')
            ->and($event->attributes['type'])->toBe('order.paid')
            ->and($event->attributes['subject'])->toBe('order-1001')
            ->and($event->attributes['time'])->toBe('2026-11-12T10:00:00.123Z')
            ->and($event->attributes['source'])->not->toBe('')
            ->and($event->headers)->toBe(['tenant' => 'demo'])
            ->and($event->topic)->toBe('orders');
    });

    it('delivers a message without an aggregate', function () use ($transport, $delivered): void {
        $transport()->publish(outboxMessage(['aggregateId' => null, 'headers' => []]));

        expect($delivered()[0]->attributes)->not->toHaveKey('subject')
            ->and($delivered()[0]->headers)->toBe([]);
    });

    it('delivers messages in the order they are published', function () use ($transport, $delivered): void {
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $id = MessageId::generate();
            $transport()->publish(outboxMessage(['id' => $id, 'aggregateSeq' => $i]));
        }

        expect(array_map(fn (CloudEvent $e): string => $e->attributes['id'], $delivered()))
            ->toBe(array_map(fn (MessageId $id): string => $id->value, $ids));
    });
}
