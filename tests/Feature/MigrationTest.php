<?php

declare(strict_types=1);

use Azhimov\Outbox\OutboxServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

beforeEach(fn () => migrateOutboxTables());

it('creates the outbox and aggregate counter tables', function (): void {
    expect(Schema::getColumnListing('outbox_messages'))->toEqualCanonicalizing([
        'id', 'topic', 'type', 'aggregate_id', 'aggregate_seq', 'payload', 'headers', 'status',
        'attempts', 'available_at', 'lease_owner', 'lease_until', 'last_error', 'created_at', 'published_at',
    ])->and(Schema::getColumnListing('outbox_aggregates'))->toEqualCanonicalizing(['aggregate_id', 'last_seq']);
});

it('stores message ids in a uuid column', function (): void {
    $type = Schema::getColumnType('outbox_messages', 'id');

    // Native uuid on PostgreSQL, char(36) on MySQL, varchar on SQLite.
    expect(in_array($type, ['uuid', 'char', 'varchar'], true))->toBeTrue("unexpected type {$type}");
});

it('keeps one sequence number per aggregate', function (): void {
    $row = fn (string $id, int $seq): array => [
        'id' => $id, 'topic' => 'orders', 'type' => 'order.paid',
        'aggregate_id' => 'order-1', 'aggregate_seq' => $seq,
        'payload' => '{}', 'headers' => '{}', 'status' => 'pending', 'attempts' => 0,
        'available_at' => 0, 'created_at' => 0,
    ];

    DB::table('outbox_messages')->insert($row('0193a2c4-6f1e-7b3a-9c2d-000000000001', 1));

    expect(fn () => DB::table('outbox_messages')->insert($row('0193a2c4-6f1e-7b3a-9c2d-000000000002', 1)))
        ->toThrow(QueryException::class);
});

it('drops both tables on rollback', function (): void {
    $migration = require __DIR__.'/../../database/migrations/create_outbox_tables.php.stub';
    $migration->down();

    expect(Schema::hasTable('outbox_messages'))->toBeFalse()
        ->and(Schema::hasTable('outbox_aggregates'))->toBeFalse();
});

it('uses the configured connection and table names', function (): void {
    config(['outbox.table' => 'custom_outbox', 'outbox.aggregates_table' => 'custom_aggregates']);
    migrateOutboxTables();

    expect(Schema::hasTable('custom_outbox'))->toBeTrue()
        ->and(Schema::hasTable('custom_aggregates'))->toBeTrue();

    $migration = require __DIR__.'/../../database/migrations/create_outbox_tables.php.stub';
    $migration->down();
});

it('publishes the migration under the outbox-migrations tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(OutboxServiceProvider::class, 'outbox-migrations');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toEndWith('_create_outbox_tables.php');
});
