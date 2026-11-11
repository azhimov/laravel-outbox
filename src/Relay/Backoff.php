<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Relay;

use Random\Randomizer;

/**
 * Delay before retry n: min(base * multiplier^(n-1), max), spread by +/- jitter share,
 * so many messages that failed together do not all come back at the same moment.
 */
final readonly class Backoff
{
    private Randomizer $randomizer;

    public function __construct(
        private int $baseMs,
        private float $multiplier,
        private int $maxMs,
        private float $jitter,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer;
    }

    public static function from(RelayOptions $options): self
    {
        return new self($options->backoffBaseMs, $options->backoffMultiplier, $options->backoffMaxMs, $options->backoffJitter);
    }

    public function delayMs(int $attempt): int
    {
        $delay = (int) min($this->baseMs * $this->multiplier ** (max(1, $attempt) - 1), $this->maxMs);

        if ($this->jitter === 0.0 || $delay === 0) {
            return $delay;
        }

        $spread = (int) ($delay * $this->jitter);

        return $this->randomizer->getInt(max(0, $delay - $spread), min($this->maxMs, $delay + $spread));
    }
}
