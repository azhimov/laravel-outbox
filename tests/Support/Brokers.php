<?php

declare(strict_types=1);

/**
 * Broker integration tests run when the broker is configured, as in CI:
 * OUTBOX_TEST_RABBITMQ=1 (+ RABBITMQ_HOST, RABBITMQ_PORT), OUTBOX_TEST_REDIS=1 (+ REDIS_HOST, REDIS_PORT).
 */
function rabbitMqAvailable(): bool
{
    return getenv('OUTBOX_TEST_RABBITMQ') === '1';
}

function redisAvailable(): bool
{
    return getenv('OUTBOX_TEST_REDIS') === '1';
}

/**
 * @return array{host: string, port: int, user: string, password: string, vhost: string}
 */
function rabbitMqSettings(): array
{
    return [
        'host' => getenv('RABBITMQ_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('RABBITMQ_PORT') ?: 5672),
        'user' => 'guest',
        'password' => 'guest',
        'vhost' => '/',
    ];
}
