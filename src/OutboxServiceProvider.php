<?php

declare(strict_types=1);

namespace Azhimov\Outbox;

use Azhimov\Outbox\Contracts\Outbox;
use Azhimov\Outbox\Contracts\Transport;
use Azhimov\Outbox\Transports\TransportManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class OutboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), 'outbox');

        $this->app->singleton(Outbox::class, function (Application $app): Outbox {
            $config = $app->make(Repository::class);

            return new DatabaseOutbox(
                connection: self::connection($app->make(DatabaseManager::class), $config),
                table: self::string($config, 'outbox.table', 'outbox_messages'),
                aggregatesTable: self::string($config, 'outbox.aggregates_table', 'outbox_aggregates'),
                strictTransaction: $config->get('outbox.strict_transaction', true) !== false,
            );
        });

        $this->app->singleton(TransportManager::class, fn (Application $app): TransportManager => new TransportManager($app));

        $this->app->bind(Transport::class, function (Application $app): Transport {
            $transport = $app->make(TransportManager::class)->driver();

            if (! $transport instanceof Transport) {
                throw new LogicException('Outbox transports must implement '.Transport::class.'.');
            }

            return $transport;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::configPath() => config_path('outbox.php'),
            ], 'outbox-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_outbox_tables.php.stub' => database_path(
                    'migrations/'.date('Y_m_d_His').'_create_outbox_tables.php',
                ),
            ], 'outbox-migrations');
        }
    }

    private static function connection(DatabaseManager $db, Repository $config): Connection
    {
        $name = $config->get('outbox.connection');

        return $db->connection(is_string($name) && $name !== '' ? $name : null);
    }

    private static function string(Repository $config, string $key, string $default): string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function configPath(): string
    {
        return __DIR__.'/../config/outbox.php';
    }
}
