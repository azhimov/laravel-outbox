<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Connection settings for the real server named in OUTBOX_TEST_DB (mysql|pgsql),
 * read from DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD. null = SQLite.
 *
 * @return array{0: string, 1: array<string, mixed>}|null
 */
function outboxTestDatabase(): ?array
{
    $driver = getenv('OUTBOX_TEST_DB');

    if ($driver !== 'mysql' && $driver !== 'pgsql') {
        return null;
    }

    return [$driver, [
        'driver' => $driver,
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: ($driver === 'mysql' ? '3306' : '5432'),
        'database' => getenv('DB_DATABASE') ?: 'outbox',
        'username' => getenv('DB_USERNAME') ?: ($driver === 'mysql' ? 'root' : 'postgres'),
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
        'collation' => $driver === 'mysql' ? 'utf8mb4_unicode_ci' : null,
        'prefix' => '',
        'schema' => 'public',
        'sslmode' => 'prefer',
    ]];
}

function usesRealDatabase(): bool
{
    return outboxTestDatabase() !== null;
}

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
