<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Inbox;

enum InboxOutcome
{
    /** The handler ran and its changes committed together with the inbox mark. */
    case Processed;

    /** This consumer had already processed the message; the handler did not run. */
    case AlreadyProcessed;
}
