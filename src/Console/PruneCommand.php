<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Console;

use Azhimov\Outbox\Support\MessageStatus;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Date;

final class PruneCommand extends Command
{
    protected $signature = 'outbox:prune
        {--published-older-than=7d : Age of published messages to delete: 30s, 15m, 12h, 7d}
        {--chunk=1000 : Rows deleted per statement}';

    protected $description = 'Delete published outbox messages (pending and failed ones are kept)';

    private const UNITS = ['s' => 1, 'm' => 60, 'h' => 3_600, 'd' => 86_400];

    public function handle(DatabaseManager $db, Repository $config): int
    {
        $age = $this->option('published-older-than');
        $chunk = $this->option('chunk');

        if (! is_string($age) || preg_match('/^([1-9]\d*)([smhd])$/', $age, $match) !== 1) {
            $this->error('--published-older-than needs a positive number and a unit: 30s, 15m, 12h, 7d.');

            return self::FAILURE;
        }

        $chunkSize = is_numeric($chunk) && (int) $chunk > 0 ? (int) $chunk : 1_000;
        $cutoff = Date::now()->getTimestampMs() - (int) $match[1] * self::UNITS[$match[2]] * 1_000;

        $connection = $config->get('outbox.connection');
        $table = $config->get('outbox.table');
        $outbox = fn () => $db->connection(is_string($connection) && $connection !== '' ? $connection : null)
            ->table(is_string($table) && $table !== '' ? $table : 'outbox_messages');

        // Counters in outbox_aggregates are kept: removing one would restart an aggregate's sequence.
        $deleted = 0;
        do {
            $ids = $outbox()
                ->where('status', MessageStatus::Published->value)
                ->where('published_at', '<', $cutoff)
                ->limit($chunkSize)
                ->pluck('id')
                ->all();

            $deleted += $ids === [] ? 0 : $outbox()->whereIn('id', $ids)->delete();
        } while (count($ids) === $chunkSize);

        $this->info("Pruned {$deleted} published message(s).");

        return self::SUCCESS;
    }
}
