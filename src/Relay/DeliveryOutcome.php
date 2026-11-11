<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Relay;

enum DeliveryOutcome
{
    case Published;

    /** The transport threw; the message comes back after a backoff. */
    case Failed;

    /** The transport threw on the last allowed attempt: the message is now failed. */
    case DeadLettered;

    /**
     * The broker call returned, but another worker had taken the message over:
     * it may now be delivered twice (at-least-once), and only the owner may mark it.
     */
    case LeaseLost;
}
