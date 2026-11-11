<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Events;

final readonly class InboxDuplicateSkipped
{
    public function __construct(
        public string $consumer,
        public string $messageId,
    ) {}
}
