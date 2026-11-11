<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Transports;

use Azhimov\Outbox\Contracts\Transport;
use Azhimov\Outbox\Support\OutboxMessage;

/**
 * Accepts and drops every message: for environments where nothing should be sent.
 */
final class NullTransport implements Transport
{
    public function publish(OutboxMessage $message): void {}
}
