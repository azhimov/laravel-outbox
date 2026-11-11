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
    | Where the relay delivers messages. Transport settings are added
    | together with each transport.
    |
    */

    'transport' => env('OUTBOX_TRANSPORT', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Relay
    |--------------------------------------------------------------------------
    */

    'relay' => [
        'batch_size' => 100,
        'sleep_ms' => 1000,
        'lease_seconds' => 30,
        'max_attempts' => 10,
    ],

];
