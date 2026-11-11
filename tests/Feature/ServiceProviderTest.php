<?php

declare(strict_types=1);

use Azhimov\Outbox\OutboxServiceProvider;
use Illuminate\Support\ServiceProvider;

it('merges the package config', function (): void {
    expect(config('outbox.table'))->toBe('outbox_messages')
        ->and(config('outbox.inbox_table'))->toBe('inbox_messages')
        ->and(config('outbox.transport'))->toBe('log')
        ->and(config('outbox.relay.lease_seconds'))->toBe(30);
});

it('publishes the config under the outbox-config tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(OutboxServiceProvider::class, 'outbox-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toBe(config_path('outbox.php'));
});
