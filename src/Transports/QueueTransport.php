<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Transports;

use Azhimov\Outbox\Contracts\Transport;
use Azhimov\Outbox\Jobs\DeliverOutboxMessage;
use Azhimov\Outbox\Support\CloudEvent;
use Azhimov\Outbox\Support\OutboxMessage;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

/**
 * Delivers through a Laravel queue, for applications without a message broker.
 * Each message becomes a DeliverOutboxMessage job, handled by the handler mapped to its type.
 */
final readonly class QueueTransport implements Transport
{
    public function __construct(
        private QueueFactory $queues,
        private ?string $connection,
        private ?string $queue,
        private string $source,
    ) {}

    public function publish(OutboxMessage $message): void
    {
        $job = new DeliverOutboxMessage(CloudEvent::fromMessage($message, $this->source));
        $queue = $this->queues->connection($this->connection);

        $this->queue === null ? $queue->push($job) : $queue->pushOn($this->queue, $job);
    }
}
