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
        // CI runs the whole suite against real servers as well (OUTBOX_TEST_DB=mysql|pgsql).
        // Without it the suite uses Testbench's in-memory SQLite.
        $database = outboxTestDatabase();

        if ($database !== null) {
            [$driver, $connection] = $database;
            $app['config']->set('database.default', $driver);
            $app['config']->set("database.connections.{$driver}", $connection);
        }
    }
}
