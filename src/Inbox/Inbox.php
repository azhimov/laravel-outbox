<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Inbox;

use Azhimov\Outbox\Events\InboxDuplicateSkipped;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Consumer-side deduplication: the mark "consumer processed message" and the
 * handler's business change are committed in one transaction, or neither is.
 *
 * insertOrIgnore claims the (consumer, message_id) row first. A concurrent duplicate
 * blocks on that row until the first transaction ends, then either sees it (skip)
 * or, if the first rolled back, claims it and runs the handler itself.
 */
final readonly class Inbox
{
    public function __construct(
        private Connection $connection,
        private string $table,
        /** @var Closure(): Dispatcher Resolved per event, so Event::fake() in application tests is honoured. */
        private Closure $events,
    ) {}

    /**
     * @param  Closure(): mixed  $handler  must write to the same connection as the inbox
     */
    public function handle(string $consumer, string $messageId, Closure $handler): InboxOutcome
    {
        if (trim($consumer) === '' || trim($messageId) === '') {
            throw new InvalidArgumentException('Inbox consumer and message id must not be empty.');
        }

        $outcome = $this->connection->transaction(function () use ($consumer, $messageId, $handler): InboxOutcome {
            $claimed = $this->connection->table($this->table)->insertOrIgnore([
                'consumer' => $consumer,
                'message_id' => $messageId,
                'processed_at' => Date::now()->getTimestampMs(),
            ]);

            if ($claimed === 0) {
                return InboxOutcome::AlreadyProcessed;
            }

            $handler();

            return InboxOutcome::Processed;
        });

        if ($outcome === InboxOutcome::AlreadyProcessed) {
            ($this->events)()->dispatch(new InboxDuplicateSkipped($consumer, $messageId));
        }

        return $outcome;
    }
}
