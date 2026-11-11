<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Exceptions;

use LogicException;

final class NotInTransaction extends LogicException
{
    public static function on(string $connection): self
    {
        return new self(
            "Outbox::publish() was called outside a transaction on the [{$connection}] connection. "
            .'Wrap the business change and publish() in DB::connection(...)->transaction(), '
            .'or set outbox.strict_transaction to false to accept losing the guarantee.'
        );
    }
}
