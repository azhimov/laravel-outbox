<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Relay;

final readonly class RelayResult
{
    public function __construct(
        public int $claimed = 0,
        public int $published = 0,
        public int $failed = 0,
        public int $leaseLost = 0,
        public int $deadLettered = 0,
    ) {}

    public function with(DeliveryOutcome $outcome): self
    {
        return new self(
            claimed: $this->claimed,
            published: $this->published + ($outcome === DeliveryOutcome::Published ? 1 : 0),
            failed: $this->failed + ($outcome === DeliveryOutcome::Failed ? 1 : 0),
            leaseLost: $this->leaseLost + ($outcome === DeliveryOutcome::LeaseLost ? 1 : 0),
            deadLettered: $this->deadLettered + ($outcome === DeliveryOutcome::DeadLettered ? 1 : 0),
        );
    }
}
