<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Events;

use Azhimov\Outbox\Support\OutboxMessage;

/**
 * One delivery attempt failed. retryAt (unix ms) is when the relay tries again;
 * null means this was the last attempt and MessageFailed follows.
 */
final readonly class MessagePublishFailed
{
    public function __construct(
        public OutboxMessage $message,
        public string $error,
        public int $attempt,
        public ?int $retryAt,
    ) {}
}
