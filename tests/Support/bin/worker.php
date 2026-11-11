<?php

declare(strict_types=1);

/*
 * A second database client for concurrency tests, run as a separate process.
 *
 *   php worker.php publish-and-hold <aggregate> <hold-ms>
 *     Opens a transaction, publishes one message, prints "published <seq>",
 *     keeps the transaction (and the aggregate's counter row) open for <hold-ms>,
 *     then commits and prints "committed".
 *
 *   php worker.php inbox-and-hold <consumer> <message-id> <hold-ms>
 *     Handles the message through the inbox, prints "handled", holds the transaction
 *     for <hold-ms>, commits and prints "committed".
 *
 *   php worker.php relay-until-empty <worker-name> <batch-size>
 *     Runs a relay with DatabaseRecordingTransport until two batches in a row are empty.
 */

use Azhimov\Outbox\Facades\Inbox;
use Azhimov\Outbox\Facades\Outbox;
use Azhimov\Outbox\OutboxServiceProvider;
use Azhimov\Outbox\Relay\Relay;
use Azhimov\Outbox\Relay\RelayOptions;
use Azhimov\Outbox\Tests\Fixtures\DatabaseRecordingTransport;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../../vendor/autoload.php';
require __DIR__.'/../Database.php';

$app = Application::create(options: ['extra' => ['providers' => [OutboxServiceProvider::class]]]);

$database = outboxTestDatabase() ?? throw new RuntimeException('worker.php needs OUTBOX_TEST_DB=mysql|pgsql');
[$driver, $connection] = $database;
$app['config']->set('database.default', $driver);
$app['config']->set("database.connections.{$driver}", $connection);

[, $command, $arg1, $arg2, $arg3] = $argv + [null, null, null, '0', '0'];

if ($command === 'inbox-and-hold' && is_string($arg1)) {
    Inbox::handle($arg1, (string) $arg2, function () use ($arg3): void {
        DB::table('test_balances')->where('account', 'acc-1')->increment('amount', 100);
        echo "handled\n";
        usleep((int) $arg3 * 1000);
    });

    echo "committed\n";
    exit(0);
}

if ($command === 'relay-until-empty' && is_string($arg1)) {
    $relay = $app->make(Relay::class, [
        'transport' => new DatabaseRecordingTransport($arg1),
        'options' => new RelayOptions(
            batchSize: (int) $arg2,
            leaseMs: 30_000,
            maxAttempts: 10,
            backoffBaseMs: 0,
            backoffMultiplier: 1.0,
            backoffMaxMs: 0,
            backoffJitter: 0.0,
        ),
    ]);

    $empty = 0;
    $published = 0;
    while ($empty < 2) {
        $result = $relay->runOnce();
        $published += $result->published;
        $empty = $result->claimed === 0 ? $empty + 1 : 0;
    }

    echo "published {$published}\n";
    exit(0);
}

if ($command !== 'publish-and-hold' || ! is_string($arg1)) {
    fwrite(STDERR, "usage: worker.php publish-and-hold <aggregate> <hold-ms> | relay-until-empty <name> <batch>\n");
    exit(2);
}

$aggregate = $arg1;
$holdMs = $arg2;

DB::transaction(function () use ($aggregate, $holdMs): void {
    $id = Outbox::publish(topic: 'orders', type: 'order.paid', payload: ['by' => 'worker'], aggregateId: $aggregate);
    $seq = DB::table('outbox_messages')->where('id', $id->value)->value('aggregate_seq');

    echo "published {$seq}\n";
    usleep((int) $holdMs * 1000);
});

echo "committed\n";
