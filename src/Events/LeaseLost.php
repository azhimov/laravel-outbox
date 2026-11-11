<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Events;

use Azhimov\Outbox\Support\OutboxMessage;

/**
 * The transport accepted a message after this worker's lease had expired and another
 * worker had taken it over: the message may be delivered twice. Often means
 * lease_seconds is shorter than the broker calls.
 */
final readonly class LeaseLost
{
    public function __construct(public OutboxMessage $message) {}
}
