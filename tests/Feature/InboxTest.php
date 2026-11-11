<?php

declare(strict_types=1);

use Azhimov\Outbox\Facades\Inbox;
use Azhimov\Outbox\Inbox\InboxOutcome;
use Azhimov\Outbox\OutboxServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

beforeEach(function (): void {
    migrateInboxTable();
    $this->freezeTime();

    Schema::dropIfExists('test_balances');
    Schema::create('test_balances', function (Blueprint $table): void {
        $table->string('account')->primary();
        $table->integer('amount');
    });
    DB::table('test_balances')->insert(['account' => 'acc-1', 'amount' => 0]);
});

function credit(int $amount): void
{
    DB::table('test_balances')->where('account', 'acc-1')->increment('amount', $amount);
}

function balance(): int
{
    return (int) DB::table('test_balances')->where('account', 'acc-1')->value('amount');
}

it('runs the handler once per consumer and message id', function (): void {
    $first = Inbox::handle('billing', 'msg-1', fn () => credit(100));
    $second = Inbox::handle('billing', 'msg-1', fn () => credit(100));

    expect($first)->toBe(InboxOutcome::Processed)
        ->and($second)->toBe(InboxOutcome::AlreadyProcessed)
        ->and(balance())->toBe(100);
});

it('records when the message was processed', function (): void {
    Inbox::handle('billing', 'msg-1', fn () => null);

    expect((int) DB::table('inbox_messages')->where(['consumer' => 'billing', 'message_id' => 'msg-1'])->value('processed_at'))
        ->toBe(now()->getTimestampMs());
});

it('lets different consumers process the same message', function (): void {
    Inbox::handle('billing', 'msg-1', fn () => credit(100));
    $other = Inbox::handle('loyalty', 'msg-1', fn () => credit(5));

    expect($other)->toBe(InboxOutcome::Processed)
        ->and(balance())->toBe(105);
});

it('rolls back the mark together with the business change when the handler fails', function (): void {
    try {
        Inbox::handle('billing', 'msg-1', function (): void {
            credit(100);

            throw new RuntimeException('ledger unavailable');
        });
    } catch (RuntimeException) {
    }

    expect(balance())->toBe(0)
        ->and(DB::table('inbox_messages')->count())->toBe(0);

    // The redelivery is processed, because nothing was committed.
    expect(Inbox::handle('billing', 'msg-1', fn () => credit(100)))->toBe(InboxOutcome::Processed)
        ->and(balance())->toBe(100);
});

it('rethrows the handler exception', function (): void {
    Inbox::handle('billing', 'msg-1', fn () => throw new DomainException('insufficient funds'));
})->throws(DomainException::class, 'insufficient funds');

it('rejects an empty consumer or message id', function (string $consumer, string $id): void {
    Inbox::handle($consumer, $id, fn () => null);
})->throws(InvalidArgumentException::class)->with([
    'empty consumer' => ['', 'msg-1'],
    'empty id' => ['billing', ' '],
]);

it('publishes the inbox migration under its own tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(OutboxServiceProvider::class, 'outbox-inbox-migrations');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toEndWith('_create_inbox_messages_table.php');
});

it('processes a duplicate delivered concurrently only once', function (): void {
    // A second process handles msg-1 and holds its transaction for 1.5 s.
    $worker = startWorker(['inbox-and-hold', 'billing', 'msg-1', '1500']);
    $worker->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'handled'));

    // The duplicate waits on the inbox row, then sees it committed and skips.
    $outcome = Inbox::handle('billing', 'msg-1', fn () => credit(100));
    $worker->wait();

    expect($worker->getOutput())->toContain('committed')
        ->and($outcome)->toBe(InboxOutcome::AlreadyProcessed)
        ->and(balance())->toBe(100);
})->skip(fn (): bool => ! usesRealDatabase(), 'needs a real server: SQLite has no row locks');
