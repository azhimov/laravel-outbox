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
}
