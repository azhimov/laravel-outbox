<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The broker did not accept a message. The relay retries it after a backoff.
 */
final class TransportFailed extends RuntimeException
{
    public static function because(string $transport, string $reason, ?Throwable $previous = null): self
    {
        return new self("{$transport}: {$reason}", 0, $previous);
    }
}
