<?php

declare(strict_types=1);

use Azhimov\Outbox\Support\MessageStatus;

it('uses short lower-case values for the status column', function (): void {
    expect(array_map(fn (MessageStatus $s): string => $s->value, MessageStatus::cases()))
        ->toBe(['pending', 'processing', 'published', 'failed']);
});
