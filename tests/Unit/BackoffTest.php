<?php

declare(strict_types=1);

use Azhimov\Outbox\Relay\Backoff;
use Random\Engine\Mt19937;
use Random\Randomizer;

it('grows exponentially up to the maximum', function (): void {
    $backoff = new Backoff(baseMs: 1_000, multiplier: 2.0, maxMs: 10_000, jitter: 0.0);

    expect(array_map(fn (int $n): int => $backoff->delayMs($n), [1, 2, 3, 4, 5, 6, 50]))
        ->toBe([1_000, 2_000, 4_000, 8_000, 10_000, 10_000, 10_000]);
});

it('spreads delays by the jitter share around the exponential value', function (): void {
    $backoff = new Backoff(baseMs: 1_000, multiplier: 2.0, maxMs: 60_000, jitter: 0.5, randomizer: new Randomizer(new Mt19937(42)));

    $delays = array_map(fn () => $backoff->delayMs(3), range(1, 200));

    expect(min($delays))->toBeGreaterThanOrEqual(2_000)
        ->and(max($delays))->toBeLessThanOrEqual(6_000)
        ->and(count(array_unique($delays)))->toBeGreaterThan(50);
});

it('never exceeds the maximum with jitter', function (): void {
    $backoff = new Backoff(baseMs: 1_000, multiplier: 2.0, maxMs: 5_000, jitter: 1.0, randomizer: new Randomizer(new Mt19937(7)));

    expect(max(array_map(fn () => $backoff->delayMs(10), range(1, 200))))->toBeLessThanOrEqual(5_000);
});

it('treats attempt numbers below 1 as the first attempt', function (): void {
    expect((new Backoff(1_000, 2.0, 10_000, 0.0))->delayMs(0))->toBe(1_000);
});
