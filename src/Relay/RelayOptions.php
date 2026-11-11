<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Relay;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final readonly class RelayOptions
{
    public function __construct(
        public int $batchSize,
        /** How long a claimed message belongs to one worker; longer than any broker call. */
        public int $leaseMs,
        /** Claims before a message is moved to failed (the dead letter). */
        public int $maxAttempts,
        public int $backoffBaseMs,
        public float $backoffMultiplier,
        public int $backoffMaxMs,
        /** 0..1: the share of each delay that is randomized. */
        public float $backoffJitter,
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Relay batch size must be at least 1.');
        }

        if ($leaseMs < 1_000) {
            throw new InvalidArgumentException('Relay lease must be at least one second.');
        }

        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Relay max attempts must be at least 1.');
        }

        if ($backoffBaseMs < 0 || $backoffMaxMs < $backoffBaseMs || $backoffMultiplier < 1.0) {
            throw new InvalidArgumentException('Relay backoff needs base >= 0, max >= base and multiplier >= 1.');
        }

        if ($backoffJitter < 0.0 || $backoffJitter > 1.0) {
            throw new InvalidArgumentException('Relay backoff jitter must be between 0 and 1.');
        }
    }

    public static function fromConfig(Repository $config, ?int $batchSize = null): self
    {
        $int = fn (string $key, int $default): int => is_numeric($value = $config->get("outbox.relay.{$key}")) ? (int) $value : $default;
        $float = fn (string $key, float $default): float => is_numeric($value = $config->get("outbox.relay.{$key}")) ? (float) $value : $default;

        return new self(
            batchSize: $batchSize ?? $int('batch_size', 100),
            leaseMs: $int('lease_seconds', 30) * 1_000,
            maxAttempts: $int('max_attempts', 10),
            backoffBaseMs: $int('backoff.base_ms', 1_000),
            backoffMultiplier: $float('backoff.multiplier', 2.0),
            backoffMaxMs: $int('backoff.max_ms', 300_000),
            backoffJitter: $float('backoff.jitter', 0.2),
        );
    }
}
