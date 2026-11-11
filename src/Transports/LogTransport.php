<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Transports;

use Azhimov\Outbox\Contracts\Transport;
use Azhimov\Outbox\Support\CloudEvent;
use Azhimov\Outbox\Support\OutboxMessage;
use Psr\Log\LoggerInterface;

/**
 * Writes each message to a log channel: for local development without a broker.
 */
final readonly class LogTransport implements Transport
{
    public function __construct(
        private LoggerInterface $logger,
        private string $source,
    ) {}

    public function publish(OutboxMessage $message): void
    {
        $event = CloudEvent::fromMessage($message, $this->source);

        $this->logger->info('Outbox message published', [
            'topic' => $event->topic,
            'headers' => [...$event->prefixedAttributes(), ...$event->headers],
            'body' => $event->data,
        ]);
    }
}
