<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Tests;

use Azhimov\Outbox\OutboxServiceProvider;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [OutboxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // CI runs the whole suite against real servers as well:
        // OUTBOX_TEST_DB=mysql|pgsql plus DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD.
        // Without it the suite uses Testbench's in-memory SQLite.
        $driver = getenv('OUTBOX_TEST_DB');

        if ($driver === 'mysql' || $driver === 'pgsql') {
            $app['config']->set('database.default', $driver);
            $app['config']->set("database.connections.{$driver}", [
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
            ]);
        }
    }
}
