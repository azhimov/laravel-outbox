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

    private bool $stopping = false;

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

        // SIGTERM / SIGINT: finish the batch in hand (its leases would otherwise have to
        // expire before another worker could take it), then exit.
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
        });

        $this->line('outbox: relaying (pid '.getmypid().')');

        for ($batches = 1; ! $this->stopping && ($maxBatches === 0 || $batches <= $maxBatches); $batches++) {
            $result = $relay->runOnce();

            if ($result->claimed > 0) {
                $this->report($result);
            } else {
                $this->sleep($sleepMs);
            }
        }

        if ($this->stopping) {
            $this->line('outbox: stopping');
        }

        return self::SUCCESS;
    }

    /**
     * Sleeps in short slices so a stop signal does not wait out the whole interval.
     */
    private function sleep(int $milliseconds): void
    {
        for ($slept = 0; $slept < $milliseconds && ! $this->stopping; $slept += 50) {
            usleep(min(50, $milliseconds - $slept) * 1_000);
        }
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
