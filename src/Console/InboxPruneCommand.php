<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Date;

final class InboxPruneCommand extends Command
{
    protected $signature = 'inbox:prune
        {--older-than=30d : Age of inbox records to delete: 12h, 30d. Keep it longer than any redelivery window.}';

    protected $description = 'Delete old inbox deduplication records';

    private const UNITS = ['s' => 1, 'm' => 60, 'h' => 3_600, 'd' => 86_400];

    public function handle(DatabaseManager $db, Repository $config): int
    {
        $age = $this->option('older-than');

        if (! is_string($age) || preg_match('/^([1-9]\d*)([smhd])$/', $age, $match) !== 1) {
            $this->error('--older-than needs a positive number and a unit: 30s, 15m, 12h, 30d.');

            return self::FAILURE;
        }

        $connection = $config->get('outbox.inbox.connection') ?? $config->get('outbox.connection');
        $table = $config->get('outbox.inbox_table');

        $deleted = $db->connection(is_string($connection) && $connection !== '' ? $connection : null)
            ->table(is_string($table) && $table !== '' ? $table : 'inbox_messages')
            ->where('processed_at', '<', Date::now()->getTimestampMs() - (int) $match[1] * self::UNITS[$match[2]] * 1_000)
            ->delete();

        $this->info("Pruned {$deleted} inbox record(s).");

        return self::SUCCESS;
    }
}
