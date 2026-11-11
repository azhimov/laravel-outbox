<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Transports;

use Azhimov\Outbox\Contracts\Transport;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Log\LogManager;
use Illuminate\Support\Manager;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Resolves transports by name (outbox.transport). Custom ones: extend('name', fn ($app) => ...).
 */
final class TransportManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $name = $this->config->get('outbox.transport');

        return is_string($name) && $name !== '' ? $name : 'log';
    }

    /**
     * CloudEvents source attribute: outbox.source, or the application name.
     */
    public function source(): string
    {
        foreach (['outbox.source', 'app.name'] as $key) {
            $value = $this->config->get($key);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return 'laravel';
    }

    protected function createLogDriver(): Transport
    {
        /** @var LogManager $log */
        $log = $this->container->make('log');
        $channel = $this->setting('log', 'channel');

        return new LogTransport($channel === null ? $log->driver() : $log->channel($channel), $this->source());
    }

    protected function createNullDriver(): Transport
    {
        return new NullTransport;
    }

    protected function createQueueDriver(): Transport
    {
        return new QueueTransport(
            $this->container->make(QueueFactory::class),
            $this->setting('queue', 'connection'),
            $this->setting('queue', 'queue'),
            $this->source(),
        );
    }

    protected function createRabbitmqDriver(): Transport
    {
        if (! class_exists(AMQPStreamConnection::class)) {
            throw new \LogicException('The rabbitmq transport needs php-amqplib: composer require php-amqplib/php-amqplib');
        }

        $config = $this->config->get('outbox.transports.rabbitmq');

        return RabbitMqTransport::fromConfig(is_array($config) ? $config : [], $this->source());
    }

    protected function createRedisDriver(): Transport
    {
        $maxLength = $this->config->get('outbox.transports.redis.max_length');

        return new RedisStreamsTransport(
            $this->container->make(Factory::class),
            $this->setting('redis', 'connection'),
            $this->setting('redis', 'stream_prefix') ?? 'outbox:',
            is_numeric($maxLength) && (int) $maxLength > 0 ? (int) $maxLength : null,
            $this->source(),
        );
    }

    private function setting(string $transport, string $key): ?string
    {
        $value = $this->config->get("outbox.transports.{$transport}.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
