<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Facades;

use Azhimov\Outbox\Contracts\Outbox as OutboxContract;
use Azhimov\Outbox\Contracts\ShouldBeOutboxed;
use Azhimov\Outbox\Support\MessageId;
use Azhimov\Outbox\Support\OutboxMessage;
use Azhimov\Outbox\Testing\OutboxFake;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static MessageId publish(string $topic, string $type, array<array-key, mixed> $payload, ?string $aggregateId = null, array<string, string> $headers = [])
 * @method static MessageId publishEvent(ShouldBeOutboxed $event)
 * @method static list<OutboxMessage> published(?string $type = null, (Closure(OutboxMessage): bool)|null $callback = null)
 * @method static void assertPublished(string $type, (Closure(OutboxMessage): bool)|null $callback = null)
 * @method static void assertPublishedTimes(string $type, int $times)
 * @method static void assertNotPublished(string $type, (Closure(OutboxMessage): bool)|null $callback = null)
 * @method static void assertNothingPublished()
 *
 * @see OutboxContract
 * @see OutboxFake
 */
final class Outbox extends Facade
{
    public static function fake(): OutboxFake
    {
        $fake = new OutboxFake;

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return OutboxContract::class;
    }
}
