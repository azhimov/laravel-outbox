<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Events;

use Azhimov\Outbox\Support\OutboxMessage;

final readonly class MessagePublished
{
    public function __construct(public OutboxMessage $message) {}
}
