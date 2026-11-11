<?php

declare(strict_types=1);

use Azhimov\Outbox\Relay\Relay;
use Azhimov\Outbox\Relay\RelayOptions;
use Azhimov\Outbox\Tests\Fixtures\RecordingTransport;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, mixed>  $options
 */
function makeRelay(RecordingTransport $transport, array $options = []): Relay
{
    return app()->make(Relay::class, [
        'transport' => $transport,
        'options' => new RelayOptions(...array_merge([
            'batchSize' => 100,
            'leaseMs' => 30_000,
            'maxAttempts' => 10,
            'backoffBaseMs' => 1_000,
            'backoffMultiplier' => 2.0,
            'backoffMaxMs' => 300_000,
            'backoffJitter' => 0.0,
        ], $options)),
    ]);
}

/**
 * @return object{status: string, attempts: int|string, lease_owner: ?string, lease_until: int|string|null, available_at: int|string, published_at: int|string|null, last_error: ?string}
 */
function outboxRow(string $id): object
{
    $row = DB::table('outbox_messages')->where('id', $id)->first();

    expect($row)->not->toBeNull();

    return $row;
}
