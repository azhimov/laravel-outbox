<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Events;

use Azhimov\Outbox\Support\MessageId;

/**
 * With ordering on, a failed message holds back every later message of its aggregate
 * until it is retried. Alert on this: the aggregate's consumers see nothing new.
 */
final readonly class AggregateBlocked
{
    public function __construct(
        public string $aggregateId,
        public MessageId $blockedBy,
    ) {}
}
