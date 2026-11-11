<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Contracts;

/**
 * An event that knows how to become an outbox message.
 */
interface ShouldBeOutboxed
{
    public function topic(): string;

    public function type(): string;

    /**
     * @return array<array-key, mixed>
     */
    public function payload(): array;

    public function aggregateId(): ?string;
}
