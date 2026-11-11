<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Facades;

use Azhimov\Outbox\Inbox\Inbox as InboxService;
use Azhimov\Outbox\Inbox\InboxOutcome;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static InboxOutcome handle(string $consumer, string $messageId, Closure $handler)
 *
 * @see InboxService
 */
final class Inbox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return InboxService::class;
    }
}
