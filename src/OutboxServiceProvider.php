<?php

declare(strict_types=1);

namespace Azhimov\Outbox;

use Illuminate\Support\ServiceProvider;

final class OutboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), 'outbox');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::configPath() => config_path('outbox.php'),
            ], 'outbox-config');
        }
    }

    private static function configPath(): string
    {
        return __DIR__.'/../config/outbox.php';
    }
}
