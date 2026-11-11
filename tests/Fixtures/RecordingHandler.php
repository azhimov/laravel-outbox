<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Tests\Fixtures;

use Azhimov\Outbox\Support\ReceivedMessage;

final class RecordingHandler
{
    /** @var list<ReceivedMessage> */
    public static array $received = [];

    public function handle(ReceivedMessage $message): void
    {
        self::$received[] = $message;
    }
}
