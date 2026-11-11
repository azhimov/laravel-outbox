<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Inbox;

use Azhimov\Outbox\Jobs\DeliverOutboxMessage;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\App;
use LogicException;

/**
 * Job middleware: runs the job through the inbox, so a redelivered message is skipped.
 *
 * Message id: DeliverOutboxMessage's CloudEvents id, or the job's outboxMessageId() method.
 * Consumer: the constructor argument, the job's public $inboxConsumer, or outbox.inbox.consumer.
 */
final readonly class DeduplicateWithInbox
{
    public function __construct(private ?string $consumer = null) {}

    /**
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        $result = null;

        App::make(Inbox::class)->handle(
            $this->consumer($job),
            $this->messageId($job),
            function () use ($job, $next, &$result): void {
                $result = $next($job);
            },
        );

        return $result;
    }

    private function consumer(object $job): string
    {
        $fromJob = property_exists($job, 'inboxConsumer') ? $job->inboxConsumer : null;
        $fromConfig = App::make(Repository::class)->get('outbox.inbox.consumer');

        foreach ([$this->consumer, $fromJob, $fromConfig] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        throw new LogicException('No inbox consumer: pass one to DeduplicateWithInbox, set $inboxConsumer on the job or outbox.inbox.consumer.');
    }

    private function messageId(object $job): string
    {
        if ($job instanceof DeliverOutboxMessage) {
            return $job->event->attributes['id'] ?? throw new LogicException('Delivered message has no id.');
        }

        if (method_exists($job, 'outboxMessageId')) {
            $id = $job->outboxMessageId();

            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        throw new LogicException('DeduplicateWithInbox needs a DeliverOutboxMessage job or an outboxMessageId(): string method.');
    }
}
