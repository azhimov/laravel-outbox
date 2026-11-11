<?php

declare(strict_types=1);

use Azhimov\Outbox\Facades\Outbox;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    migrateOutboxTables();
    createDeliveriesTable();
});

it('finishes the current batch and exits on SIGTERM', function (): void {
    DB::transaction(function (): void {
        foreach (range(1, 5) as $i) {
            Outbox::publish(topic: 'orders', type: 'order.paid', payload: ['n' => $i]);
        }
    });

    // Each delivery takes 300 ms; SIGTERM arrives during the first one.
    $relay = startWorker(['relay-command', '300']);
    $relay->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'relaying'));
    usleep(150_000);
    $relay->signal(SIGTERM);

    $started = microtime(true);
    $relay->wait();

    expect($relay->getExitCode())->toBe(0, $relay->getErrorOutput())
        ->and($relay->getOutput())->toContain('stopping')
        ->and(microtime(true) - $started)->toBeLessThan(5.0)
        ->and(DB::table('test_deliveries')->count())->toBe(5)
        ->and(DB::table('outbox_messages')->where('status', 'processing')->count())->toBe(0);
})->skip(fn (): bool => ! usesRealDatabase() || ! function_exists('pcntl_signal'), 'needs a real server and pcntl');

it('exits promptly on SIGTERM while waiting for messages', function (): void {
    $relay = startWorker(['relay-command', '0', '60000']);
    $relay->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'relaying'));
    usleep(200_000);

    $started = microtime(true);
    $relay->signal(SIGTERM);
    $relay->wait();

    expect($relay->getExitCode())->toBe(0)
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
})->skip(fn (): bool => ! usesRealDatabase() || ! function_exists('pcntl_signal'), 'needs a real server and pcntl');
