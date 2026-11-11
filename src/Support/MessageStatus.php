<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Support;

enum MessageStatus: string
{
    /** Waiting for the relay (also after a failed attempt, until available_at). */
    case Pending = 'pending';

    /** Leased by a relay worker until lease_until. */
    case Processing = 'processing';

    case Published = 'published';

    /** Gave up after max_attempts: the dead letter. */
    case Failed = 'failed';
}
