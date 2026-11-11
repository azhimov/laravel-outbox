<?php

declare(strict_types=1);

use Azhimov\Outbox\Contracts\Transport;
use Azhimov\Outbox\Facades\Outbox;
use Azhimov\Outbox\Tests\Fixtures\RecordingTransport;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    migrateOutboxTables();
    $this->transport = new RecordingTransport;
    app()->instance(Transport::class, $this->transport);
});

it('relays one batch with --once', function (): void {
    foreach (range(1, 3) as $i) {
        DB::transaction(fn () => Outbox::publish(topic: 'orders', type: "t{$i}", payload: []));
    }

    $this->artisan('outbox:relay', ['--once' => true, '--batch' => 2])
        ->expectsOutputToContain('claimed 2, published 2, failed 0')
        ->assertSuccessful();

    expect($this->transport->published)->toHaveCount(2);
});

it('stops after --max-batches in loop mode', function (): void {
    DB::transaction(fn () => Outbox::publish(topic: 'orders', type: 'order.paid', payload: []));

    $this->artisan('outbox:relay', ['--max-batches' => 2, '--sleep' => 0])->assertSuccessful();

    expect($this->transport->published)->toHaveCount(1);
});
