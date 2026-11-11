<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Events;

use Azhimov\Outbox\Support\MessageId;

/**
 * A message was moved to failed (the dead letter) and needs outbox:retry. Alert on this.
 */
final readonly class MessageFailed
{
    public function __construct(
        public MessageId $id,
        public string $type,
        public ?string $aggregateId,
        public string $reason,
    ) {}
}
