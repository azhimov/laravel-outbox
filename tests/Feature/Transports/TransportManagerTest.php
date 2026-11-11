<?php

declare(strict_types=1);

use Azhimov\Outbox\Contracts\Transport;
use Azhimov\Outbox\Support\OutboxMessage;
use Azhimov\Outbox\Transports\LogTransport;
use Azhimov\Outbox\Transports\NullTransport;
use Azhimov\Outbox\Transports\QueueTransport;
use Azhimov\Outbox\Transports\TransportManager;

it('resolves the configured default transport', function (string $name, string $class): void {
    config(['outbox.transport' => $name]);

    expect(app(TransportManager::class)->driver())->toBeInstanceOf($class)
        ->and(app(Transport::class))->toBeInstanceOf($class);
})->with([
    'log' => ['log', LogTransport::class],
    'null' => ['null', NullTransport::class],
    'queue' => ['queue', QueueTransport::class],
]);

it('accepts custom transports', function (): void {
    $custom = new class implements Transport
    {
        public function publish(OutboxMessage $message): void {}
    };

    app(TransportManager::class)->extend('custom', fn () => $custom);
    config(['outbox.transport' => 'custom']);

    expect(app(TransportManager::class)->driver())->toBe($custom);
});

it('rejects an unknown transport', function (): void {
    config(['outbox.transport' => 'carrier-pigeon']);

    app(TransportManager::class)->driver();
})->throws(InvalidArgumentException::class);

it('uses the app name as CloudEvents source unless configured', function (): void {
    config(['app.name' => 'Shop', 'outbox.source' => null]);
    expect(app(TransportManager::class)->source())->toBe('Shop');

    config(['outbox.source' => 'shop-api']);
    expect(app(TransportManager::class)->source())->toBe('shop-api');
});
