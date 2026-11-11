<?php

declare(strict_types=1);

use Azhimov\Outbox\Facades\Outbox;
use Azhimov\Outbox\Support\MessageId;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    migrateOutboxTables();
    $this->freezeTime();
});

function failedMessage(): MessageId
{
    $id = DB::transaction(fn (): MessageId => Outbox::publish(topic: 'orders', type: 'order.paid', payload: []));
    DB::table('outbox_messages')->where('id', $id->value)->update(['status' => 'failed', 'attempts' => 10, 'last_error' => 'broker down']);

    return $id;
}

it('retries one failed message by id', function (): void {
    $id = failedMessage();
    $other = failedMessage();

    $this->artisan('outbox:retry', ['id' => $id->value])
        ->expectsOutputToContain('1 message(s) queued for retry')
        ->assertSuccessful();

    $row = outboxRow($id->value);
    expect($row->status)->toBe('pending')
        ->and((int) $row->attempts)->toBe(0)
        ->and((int) $row->available_at)->toBe(now()->getTimestampMs())
        ->and($row->last_error)->toBe('broker down')
        ->and(outboxRow($other->value)->status)->toBe('failed');
});

it('retries all failed messages', function (): void {
    failedMessage();
    failedMessage();
    DB::transaction(fn () => Outbox::publish(topic: 'orders', type: 'order.shipped', payload: []));

    $this->artisan('outbox:retry', ['--all-failed' => true])
        ->expectsOutputToContain('2 message(s) queued for retry')
        ->assertSuccessful();

    expect(DB::table('outbox_messages')->where('status', 'failed')->count())->toBe(0);
});

it('does not touch a message that is not failed', function (): void {
    $id = DB::transaction(fn (): MessageId => Outbox::publish(topic: 'orders', type: 'order.paid', payload: []));
    DB::table('outbox_messages')->where('id', $id->value)->update(['status' => 'published']);

    $this->artisan('outbox:retry', ['id' => $id->value])
        ->expectsOutputToContain('0 message(s) queued for retry')
        ->assertFailed();

    expect(outboxRow($id->value)->status)->toBe('published');
});

it('needs an id or --all-failed', function (): void {
    $this->artisan('outbox:retry')->assertFailed();
    $this->artisan('outbox:retry', ['id' => 'not-a-uuid'])->assertFailed();
});
