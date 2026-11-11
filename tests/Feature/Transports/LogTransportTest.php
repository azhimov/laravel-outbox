<?php

declare(strict_types=1);

use Azhimov\Outbox\Support\CloudEvent;
use Azhimov\Outbox\Transports\LogTransport;
use Azhimov\Outbox\Transports\TransportManager;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->logged = [];
    Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = $e);
});

transportContract(
    fn () => new LogTransport(Log::driver(), source: 'shop'),
    fn () => array_map(
        fn (MessageLogged $e): CloudEvent => CloudEvent::fromPrefixed($e->context['topic'], $e->context['headers'], $e->context['body']),
        test()->logged,
    ),
);

it('logs at info level', function (): void {
    (new LogTransport(Log::driver(), source: 'shop'))->publish(outboxMessage());

    expect($this->logged)->toHaveCount(1)
        ->and($this->logged[0]->level)->toBe('info')
        ->and($this->logged[0]->message)->toBe('Outbox message published');
});

it('writes to the configured channel', function (): void {
    $path = storage_path('logs/outbox-'.uniqid().'.log');
    config([
        'logging.channels.outbox' => ['driver' => 'single', 'path' => $path],
        'outbox.transport' => 'log',
        'outbox.transports.log.channel' => 'outbox',
    ]);

    app(TransportManager::class)->driver()->publish(outboxMessage());

    expect(file_get_contents($path))->toContain('Outbox message published')->toContain('order.paid');
    unlink($path);
});
