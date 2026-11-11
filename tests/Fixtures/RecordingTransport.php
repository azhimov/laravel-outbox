<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Tests\Fixtures;

use Azhimov\Outbox\Contracts\Transport;
use Azhimov\Outbox\Support\OutboxMessage;
use Closure;
use RuntimeException;

/**
 * Records what the relay hands over; can be told to fail or to run code during publish.
 */
final class RecordingTransport implements Transport
{
    /** @var list<OutboxMessage> */
    public array $published = [];

    /** @var list<string> */
    public array $failTypes = [];

    public ?Closure $during = null;

    public function publish(OutboxMessage $message): void
    {
        if ($this->during !== null) {
            ($this->during)($message);
        }

        if (in_array($message->type, $this->failTypes, true)) {
            throw new RuntimeException("broker rejected {$message->type}");
        }

        $this->published[] = $message;
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(fn (OutboxMessage $m): string => $m->type, $this->published);
    }
}
