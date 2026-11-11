<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Events;

use Azhimov\Outbox\Support\ReceivedMessage;

/**
 * The queue transport received a message whose type has no handler.
 */
final readonly class UnhandledOutboxMessage
{
    public function __construct(public ReceivedMessage $message) {}
}
