<?php

declare(strict_types=1);

use Azhimov\Outbox\Facades\Outbox;
use Azhimov\Outbox\Support\MessageId;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    migrateOutboxTables();
    $this->freezeTime();
});

function messageWithStatus(string $status, ?int $publishedAgoSeconds = null): MessageId
{
    $id = DB::transaction(fn (): MessageId => Outbox::publish(topic: 'orders', type: 'order.paid', payload: [], aggregateId: 'order-1'));
    DB::table('outbox_messages')->where('id', $id->value)->update([
        'status' => $status,
        'published_at' => $publishedAgoSeconds === null ? null : now()->subSeconds($publishedAgoSeconds)->getTimestampMs(),
    ]);

    return $id;
}

it('deletes published messages older than the given age', function (): void {
    $old = messageWithStatus('published', publishedAgoSeconds: 8 * 86_400);
    $recent = messageWithStatus('published', publishedAgoSeconds: 6 * 86_400);
    $failed = messageWithStatus('failed');
    $pending = messageWithStatus('pending');

    $this->artisan('outbox:prune', ['--published-older-than' => '7d'])
        ->expectsOutputToContain('Pruned 1 published message(s)')
        ->assertSuccessful();

    expect(DB::table('outbox_messages')->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->toEqualCanonicalizing([$recent->value, $failed->value, $pending->value])
        ->and($old)->not->toBeNull();
});

it('keeps aggregate counters so sequence numbers never restart', function (): void {
    messageWithStatus('published', publishedAgoSeconds: 30 * 86_400);

    $this->artisan('outbox:prune', ['--published-older-than' => '1d'])->assertSuccessful();

    expect((int) DB::table('outbox_aggregates')->where('aggregate_id', 'order-1')->value('last_seq'))->toBe(1);
});

it('deletes in chunks', function (): void {
    foreach (range(1, 25) as $i) {
        messageWithStatus('published', publishedAgoSeconds: 2 * 86_400);
    }

    $this->artisan('outbox:prune', ['--published-older-than' => '1d', '--chunk' => 10])
        ->expectsOutputToContain('Pruned 25 published message(s)')
        ->assertSuccessful();
});

it('understands seconds, minutes, hours and days', function (string $age, int $keptOfThree): void {
    messageWithStatus('published', publishedAgoSeconds: 30);
    messageWithStatus('published', publishedAgoSeconds: 2 * 3_600);
    messageWithStatus('published', publishedAgoSeconds: 3 * 86_400);

    $this->artisan('outbox:prune', ['--published-older-than' => $age])->assertSuccessful();

    expect(DB::table('outbox_messages')->count())->toBe($keptOfThree);
})->with([
    '10s' => ['10s', 0],
    '90m' => ['90m', 1],
    '3h' => ['3h', 2],
    '2d' => ['2d', 2],
]);

it('rejects a malformed age', function (string $age): void {
    $this->artisan('outbox:prune', ['--published-older-than' => $age])->assertFailed();
})->with(['7', 'seven days', '-1d', '0d']);
