<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Jobs;

use Azhimov\Outbox\Events\UnhandledOutboxMessage;
use Azhimov\Outbox\Inbox\DeduplicateWithInbox;
use Azhimov\Outbox\Support\CloudEvent;
use Azhimov\Outbox\Support\ReceivedMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * The consumer side of the queue transport: routes a message to the handler
 * configured for its type in outbox.transports.queue.handlers.
 */
final class DeliverOutboxMessage implements ShouldQueue
{
    use Queueable;

    /** Used by DeduplicateWithInbox when set; otherwise outbox.inbox.consumer. */
    public ?string $inboxConsumer = null;

    public function __construct(public readonly CloudEvent $event) {}

    /**
     * With outbox.transports.queue.deduplicate, redelivered messages are skipped through the inbox.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return config('outbox.transports.queue.deduplicate') === true ? [new DeduplicateWithInbox] : [];
    }

    public function handle(Container $container, Repository $config, Dispatcher $events, LoggerInterface $logger): void
    {
        $message = ReceivedMessage::fromCloudEvent($this->event);
        $handlers = $config->get('outbox.transports.queue.handlers', []);
        $class = is_array($handlers) ? ($handlers[$message->type] ?? null) : null;

        if (! is_string($class)) {
            // Not an exception: a retry would not help, and one unknown type must not block the queue.
            $events->dispatch(new UnhandledOutboxMessage($message));
            $logger->warning('No outbox handler for message type', ['type' => $message->type, 'id' => $message->id]);

            return;
        }

        $handler = $container->make($class);

        if (is_object($handler) && method_exists($handler, 'handle')) {
            $handler->handle($message);

            return;
        }

        if (is_callable($handler)) {
            $handler($message);

            return;
        }

        throw new LogicException("Outbox handler [{$class}] needs a handle(ReceivedMessage) or __invoke(ReceivedMessage) method.");
    }
}
