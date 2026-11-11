<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Support;

use Illuminate\Database\Connection;
use LogicException;

/**
 * Hands out the next sequence number of an aggregate with one atomic upsert.
 *
 * The upsert locks the aggregate's counter row until the surrounding transaction ends,
 * so a second writer of the same aggregate waits for the first one to commit. That makes
 * sequence order equal commit order, which message ids (generated before commit) are not.
 *
 * @internal
 */
final class AggregateSequence
{
    public static function next(Connection $connection, string $table, string $aggregateId): int
    {
        $grammar = $connection->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);

        $seq = match ($connection->getDriverName()) {
            // LAST_INSERT_ID(expr) stores expr for this connection: 1 on insert, last_seq + 1 on update.
            'mysql', 'mariadb' => self::mysql($connection, $wrappedTable, $aggregateId),
            'pgsql', 'sqlite' => self::lastSeq($connection->selectOne(
                "insert into {$wrappedTable} (aggregate_id, last_seq) values (?, 1) "
                .'on conflict (aggregate_id) do update set last_seq = '.$wrappedTable.'.last_seq + 1 '
                .'returning last_seq',
                [$aggregateId],
            )),
            default => throw new LogicException("The outbox does not support the [{$connection->getDriverName()}] driver."),
        };

        if (is_int($seq)) {
            return $seq;
        }

        if (is_string($seq) && ctype_digit($seq)) {
            return (int) $seq;
        }

        throw new LogicException("Could not allocate a sequence number for aggregate [{$aggregateId}].");
    }

    private static function mysql(Connection $connection, string $wrappedTable, string $aggregateId): mixed
    {
        $connection->statement(
            "insert into {$wrappedTable} (aggregate_id, last_seq) values (?, last_insert_id(1)) "
            .'on duplicate key update last_seq = last_insert_id(last_seq + 1)',
            [$aggregateId],
        );

        return self::lastSeq($connection->selectOne('select last_insert_id() as last_seq'));
    }

    private static function lastSeq(mixed $row): mixed
    {
        return is_object($row) && property_exists($row, 'last_seq') ? $row->last_seq : null;
    }
}
