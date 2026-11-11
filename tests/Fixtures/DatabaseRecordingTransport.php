<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Tests\Fixtures;

use Azhimov\Outbox\Contracts\Transport;
use Azhimov\Outbox\Support\OutboxMessage;
use Illuminate\Support\Facades\DB;

/**
 * For multi-process tests: every delivery becomes a row in test_deliveries,
 * so the parent test can see what all workers sent and in which order.
 */
final class DatabaseRecordingTransport implements Transport
{
    public function __construct(private string $worker, private int $delayMicroseconds = 2000) {}

    public function publish(OutboxMessage $message): void
    {
        // Simulated broker latency widens the window in which workers overlap.
        usleep($this->delayMicroseconds);

        DB::table('test_deliveries')->insert([
            'message_id' => $message->id->value,
            'aggregate_id' => $message->aggregateId,
            'aggregate_seq' => $message->aggregateSeq,
            'worker' => $this->worker,
            'delivered_at' => (int) (microtime(true) * 1_000_000),
        ]);
    }
}
