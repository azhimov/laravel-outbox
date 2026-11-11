<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Stringable;

/**
 * UUIDv7: unique across services and sortable by creation time.
 */
final readonly class MessageId implements Stringable
{
    private function __construct(public string $value) {}

    public static function generate(): self
    {
        return new self(Str::uuid7()->toString());
    }

    public static function fromString(string $value): self
    {
        if (! Str::isUuid($value)) {
            throw new InvalidArgumentException("Message id must be a UUID, got [{$value}].");
        }

        return new self(strtolower($value));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
