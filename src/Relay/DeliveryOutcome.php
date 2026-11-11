<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Relay;

enum DeliveryOutcome
{
    case Published;

    /** The transport threw; the message goes back for a retry (or to failed). */
    case Failed;

    /**
     * The broker call returned, but another worker had taken the message over:
     * it may now be delivered twice (at-least-once), and only the owner may mark it.
     */
    case LeaseLost;
}
