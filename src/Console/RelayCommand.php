<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Console;

use Azhimov\Outbox\Relay\Relay;
use Azhimov\Outbox\Relay\RelayOptions;
use Azhimov\Outbox\Relay\RelayResult;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

final class RelayCommand extends Command
{
    protected $signature = 'outbox:relay
        {--once : Relay one batch and exit (for cron and tests)}
        {--batch= : Messages per batch (default: outbox.relay.batch_size)}
        {--sleep= : Milliseconds to wait when the outbox is empty (default: outbox.relay.sleep_ms)}
        {--max-batches=0 : Exit after this many batches (0 = run until stopped)}';

    protected $description = 'Deliver outbox messages to the configured transport';

    public function handle(Repository $config): int
    {
        $batch = $this->option('batch');
        $relay = $this->laravel->make(Relay::class, [
            'options' => RelayOptions::fromConfig($config, is_numeric($batch) ? (int) $batch : null),
        ]);

        if ($this->option('once') === true) {
            $this->report($relay->runOnce());

            return self::SUCCESS;
        }

        $sleep = $this->option('sleep');
        $sleepMs = is_numeric($sleep) ? (int) $sleep : (is_numeric($v = $config->get('outbox.relay.sleep_ms')) ? (int) $v : 1_000);
        $maxBatches = is_numeric($m = $this->option('max-batches')) ? (int) $m : 0;

        for ($batches = 1; $maxBatches === 0 || $batches <= $maxBatches; $batches++) {
            $result = $relay->runOnce();

            if ($result->claimed > 0) {
                $this->report($result);
            } elseif ($sleepMs > 0) {
                usleep($sleepMs * 1_000);
            }
        }

        return self::SUCCESS;
    }

    private function report(RelayResult $result): void
    {
        $this->line(sprintf(
            'outbox: claimed %d, published %d, failed %d, dead-lettered %d, lease lost %d',
            $result->claimed,
            $result->published,
            $result->failed,
            $result->deadLettered,
            $result->leaseLost,
        ));
    }
}
