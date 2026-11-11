<?php

declare(strict_types=1);

use Azhimov\Outbox\Facades\Outbox;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    migrateOutboxTables();
    createDeliveriesTable();
});

it('never hands one message to two workers at once', function (): void {
    DB::transaction(function (): void {
        foreach (range(1, 300) as $i) {
            Outbox::publish(topic: 'orders', type: 'order.paid', payload: ['n' => $i]);
        }
    });

    $workers = array_map(fn (int $n) => startWorker(['relay-until-empty', "w{$n}", '10']), range(1, 4));
    foreach ($workers as $worker) {
        $worker->wait();
        expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());
    }

    $deliveries = DB::table('test_deliveries')->get();

    expect($deliveries)->toHaveCount(300)
        ->and($deliveries->pluck('message_id')->unique())->toHaveCount(300)
        ->and($deliveries->pluck('worker')->unique()->count())->toBeGreaterThan(1)
        ->and(DB::table('outbox_messages')->where('status', '!=', 'published')->count())->toBe(0);
})->skip(fn (): bool => ! usesRealDatabase(), 'needs a real server: SQLite has no row locks');
