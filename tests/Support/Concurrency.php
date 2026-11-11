<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * Starts worker.php in a separate PHP process with the test's database settings.
 *
 * @param  list<string>  $arguments
 */
function startWorker(array $arguments): Process
{
    $process = new Process(['php', __DIR__.'/bin/worker.php', ...$arguments], env: getenv(), timeout: 120);
    $process->start();

    return $process;
}

function createDeliveriesTable(): void
{
    Schema::dropIfExists('test_deliveries');
    Schema::create('test_deliveries', function (Blueprint $table): void {
        $table->id();
        $table->uuid('message_id');
        $table->string('aggregate_id')->nullable();
        $table->unsignedBigInteger('aggregate_seq')->nullable();
        $table->string('worker');
        $table->unsignedBigInteger('delivered_at');
    });
}
