<?php

declare(strict_types=1);

use Azhimov\Outbox\Exceptions\TransportFailed;
use Azhimov\Outbox\Support\CloudEvent;
use Azhimov\Outbox\Transports\RedisStreamsTransport;
use Azhimov\Outbox\Transports\TransportManager;
use Illuminate\Support\Facades\Redis;

/**
 * @return list<array<string, string>> stream entries, oldest first
 */
function streamEntries(string $stream): array
{
    $entries = Redis::connection()->command('xrange', [$stream, '-', '+']);

    return array_values(is_array($entries) ? $entries : []);
}

function streamToCloudEvent(string $topic, array $fields): CloudEvent
{
    $data = $fields['data'];
    unset($fields['data'], $fields['content-type']);

    return CloudEvent::fromPrefixed($topic, $fields, $data);
}

describe('Redis Streams', function (): void {
    beforeEach(function (): void {
        if (! redisAvailable()) {
            $this->markTestSkipped('set OUTBOX_TEST_REDIS=1 with a running Redis');
        }

        Redis::connection()->command('del', ['outbox:orders', 'outbox:invoices', 'custom:orders']);
        $this->redis = new RedisStreamsTransport(app('redis'), connection: null, prefix: 'outbox:', maxLength: null, source: 'shop');
    });

    transportContract(
        fn () => test()->redis,
        fn () => array_map(fn (array $fields): CloudEvent => streamToCloudEvent('orders', $fields), streamEntries('outbox:orders')),
    );

    it('writes one stream per topic with ce_* fields, content type and data', function (): void {
        $this->redis->publish(outboxMessage(['topic' => 'invoices']));

        [$fields] = streamEntries('outbox:invoices');

        expect(array_keys($fields))->toBe([
            'ce_specversion', 'ce_id', 'ce_type', 'ce_source', 'ce_subject', 'ce_time', 'content-type', 'tenant', 'data',
        ])->and($fields['content-type'])->toBe('application/json')
            ->and(streamEntries('outbox:orders'))->toBe([]);
    });

    it('uses the configured stream prefix', function (): void {
        (new RedisStreamsTransport(app('redis'), null, 'custom:', null, 'shop'))->publish(outboxMessage());

        expect(streamEntries('custom:orders'))->toHaveCount(1);
    });

    it('caps the stream length when asked to', function (): void {
        $transport = new RedisStreamsTransport(app('redis'), null, 'outbox:', maxLength: 5, source: 'shop');

        foreach (range(1, 300) as $i) {
            $transport->publish(outboxMessage());
        }

        // MAXLEN ~ trims in whole nodes, so the stream stays near the cap, not exactly at it.
        expect(count(streamEntries('outbox:orders')))->toBeLessThan(300);
    });

    it('is resolved by the transport manager', function (): void {
        config(['outbox.transport' => 'redis']);

        expect(app(TransportManager::class)->driver())->toBeInstanceOf(RedisStreamsTransport::class);
    });
});

it('fails with a clear error when Redis is unreachable', function (): void {
    config([
        'database.redis.outbox_down' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.5, 'read_timeout' => 0.5],
        'database.redis.client' => 'predis',
    ]);

    (new RedisStreamsTransport(app('redis'), 'outbox_down', 'outbox:', null, 'shop'))->publish(outboxMessage());
})->throws(TransportFailed::class, 'Redis');
