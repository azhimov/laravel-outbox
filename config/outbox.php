<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | Outbox rows must be written on the same connection as your business
    | change, otherwise they are not part of its transaction. null means
    | the application's default connection.
    |
    */

    'connection' => env('OUTBOX_CONNECTION'),

    'table' => 'outbox_messages',

    'aggregates_table' => 'outbox_aggregates',

    'inbox_table' => 'inbox_messages',

    /*
    |--------------------------------------------------------------------------
    | Strict transaction
    |--------------------------------------------------------------------------
    |
    | true: Outbox::publish() outside a transaction on the outbox connection
    | throws NotInTransaction, because the message would no longer commit
    | together with the business change. false: publish anyway.
    |
    */

    'strict_transaction' => filter_var(env('OUTBOX_STRICT_TRANSACTION', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Where the relay delivers messages: log, null, queue (more come with
    | each transport). Messages go out as CloudEvents 1.0 in binary mode;
    | source is their "source" attribute (default: app.name).
    |
    */

    'transport' => env('OUTBOX_TRANSPORT', 'log'),

    'source' => env('OUTBOX_SOURCE'),

    'transports' => [

        'log' => [
            'channel' => env('OUTBOX_LOG_CHANNEL'),
        ],

        'queue' => [
            'connection' => env('OUTBOX_QUEUE_CONNECTION'),
            'queue' => env('OUTBOX_QUEUE'),
            // Consumer side: message type => handler class with handle(ReceivedMessage).
            'handlers' => [
                // 'order.paid' => \App\Outbox\HandleOrderPaid::class,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Relay
    |--------------------------------------------------------------------------
    */

    /*
    | aggregate: messages with the same aggregateId are delivered one at a time,
    | in commit order; a failed one blocks its aggregate until outbox:retry.
    | none: no ordering, every message is independent.
    */

    'ordering' => env('OUTBOX_ORDERING', 'aggregate'),

    'relay' => [
        'batch_size' => (int) env('OUTBOX_RELAY_BATCH_SIZE', 100),
        'sleep_ms' => (int) env('OUTBOX_RELAY_SLEEP_MS', 1000),
        // Must be longer than the slowest broker call, or a second worker takes over.
        'lease_seconds' => (int) env('OUTBOX_RELAY_LEASE_SECONDS', 30),
        // Claims before a message is moved to failed (dead letter).
        'max_attempts' => (int) env('OUTBOX_RELAY_MAX_ATTEMPTS', 10),
        // Delay before retry n: min(base * multiplier^(n-1), max), +/- jitter share.
        'backoff' => [
            'base_ms' => 1000,
            'multiplier' => 2.0,
            'max_ms' => 300000,
            'jitter' => 0.2,
        ],
    ],

];
