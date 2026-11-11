<?php

declare(strict_types=1);

/*
 * A second database client for concurrency tests, run as a separate process:
 *   php worker.php publish-and-hold <aggregate> <hold-ms>
 * Opens a transaction, publishes one message, prints "published <seq>",
 * keeps the transaction (and the aggregate's counter row) open for <hold-ms>,
 * then commits and prints "committed".
 */

use Azhimov\Outbox\Facades\Outbox;
use Azhimov\Outbox\OutboxServiceProvider;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../../vendor/autoload.php';
require __DIR__.'/../Database.php';

$app = Application::create(options: ['extra' => ['providers' => [OutboxServiceProvider::class]]]);

$database = outboxTestDatabase() ?? throw new RuntimeException('worker.php needs OUTBOX_TEST_DB=mysql|pgsql');
[$driver, $connection] = $database;
$app['config']->set('database.default', $driver);
$app['config']->set("database.connections.{$driver}", $connection);

[, $command, $aggregate, $holdMs] = $argv + [null, null, null, '0'];

if ($command !== 'publish-and-hold' || ! is_string($aggregate)) {
    fwrite(STDERR, "usage: worker.php publish-and-hold <aggregate> <hold-ms>\n");
    exit(2);
}

DB::transaction(function () use ($aggregate, $holdMs): void {
    $id = Outbox::publish(topic: 'orders', type: 'order.paid', payload: ['by' => 'worker'], aggregateId: $aggregate);
    $seq = DB::table('outbox_messages')->where('id', $id->value)->value('aggregate_seq');

    echo "published {$seq}\n";
    usleep((int) $holdMs * 1000);
});

echo "committed\n";
