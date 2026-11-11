<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Contracts;

use Azhimov\Outbox\Support\OutboxMessage;

/**
 * Delivers one message to a broker. Returns only when the broker has accepted it;
 * any failure must be thrown, so the relay retries instead of marking it published.
 */
interface Transport
{
    public function publish(OutboxMessage $message): void;
}
