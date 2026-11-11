<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    migrateInboxTable();
    $this->freezeTime();
});

it('deletes inbox rows older than the given age', function (): void {
    DB::table('inbox_messages')->insert([
        ['consumer' => 'billing', 'message_id' => 'old', 'processed_at' => now()->subDays(31)->getTimestampMs()],
        ['consumer' => 'billing', 'message_id' => 'new', 'processed_at' => now()->subDays(29)->getTimestampMs()],
    ]);

    $this->artisan('inbox:prune', ['--older-than' => '30d'])
        ->expectsOutputToContain('Pruned 1 inbox record(s)')
        ->assertSuccessful();

    expect(DB::table('inbox_messages')->pluck('message_id')->all())->toBe(['new']);
});

it('rejects a malformed age', function (): void {
    $this->artisan('inbox:prune', ['--older-than' => 'forever'])->assertFailed();
});
