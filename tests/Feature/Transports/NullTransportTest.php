<?php

declare(strict_types=1);

use Azhimov\Outbox\Transports\NullTransport;

it('accepts and drops every message', function (): void {
    (new NullTransport)->publish(outboxMessage());

    expect(true)->toBeTrue();
});
