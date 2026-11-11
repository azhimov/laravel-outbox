<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Events;

use Azhimov\Outbox\Support\MessageId;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A message was written to the outbox. Dispatched after the business transaction
 * commits, so listeners never see a message that was rolled back.
 */
final readonly class MessageStored implements ShouldDispatchAfterCommit
{
    public function __construct(
        public MessageId $id,
        public string $topic,
        public string $type,
        public ?string $aggregateId,
    ) {}
}
