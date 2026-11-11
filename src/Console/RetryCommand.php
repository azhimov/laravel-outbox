<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Console;

use Azhimov\Outbox\Support\MessageStatus;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

final class RetryCommand extends Command
{
    protected $signature = 'outbox:retry
        {id? : Id of a failed message}
        {--all-failed : Retry every failed message}';

    protected $description = 'Send failed (dead-lettered) outbox messages again';

    public function handle(DatabaseManager $db, Repository $config): int
    {
        $id = $this->argument('id');
        $all = $this->option('all-failed') === true;

        if (! $all && ! is_string($id)) {
            $this->error('Pass a message id or --all-failed.');

            return self::FAILURE;
        }

        if (is_string($id) && ! Str::isUuid($id)) {
            $this->error("[{$id}] is not a message id.");

            return self::FAILURE;
        }

        $connection = $config->get('outbox.connection');
        $table = $config->get('outbox.table');

        $query = $db->connection(is_string($connection) && $connection !== '' ? $connection : null)
            ->table(is_string($table) && $table !== '' ? $table : 'outbox_messages')
            ->where('status', MessageStatus::Failed->value);

        if (is_string($id)) {
            $query->where('id', strtolower($id));
        }

        // last_error stays, so the reason of the previous failure is still visible.
        $count = $query->update([
            'status' => MessageStatus::Pending->value,
            'attempts' => 0,
            'available_at' => Date::now()->getTimestampMs(),
            'lease_owner' => null,
            'lease_until' => null,
        ]);

        $this->info("{$count} message(s) queued for retry.");

        return $count === 0 && ! $all ? self::FAILURE : self::SUCCESS;
    }
}
