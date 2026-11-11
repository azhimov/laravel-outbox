<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Runs the package migration from a clean state. On real MySQL / PostgreSQL servers
 * tables survive between tests, so the migration is rolled back first.
 */
function migrateOutboxTables(): void
{
    /** @var Migration $migration */
    $migration = require __DIR__.'/../../database/migrations/create_outbox_tables.php.stub';

    $migration->down();
    $migration->up();
}
