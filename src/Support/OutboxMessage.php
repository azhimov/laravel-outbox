<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Support;

use JsonException;
use UnexpectedValueException;

/**
 * A stored outbox message as the relay and transports see it.
 */
final readonly class OutboxMessage
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public MessageId $id,
        public string $topic,
        public string $type,
        public array $payload,
        public array $headers,
        public ?string $aggregateId,
        public ?int $aggregateSeq,
        public MessageStatus $status,
        public int $attempts,
        /** Unix time in milliseconds. */
        public int $createdAt,
    ) {}

    /**
     * Rehydrates a row of the outbox table. Drivers differ: MySQL and SQLite may
     * return numbers as strings, JSON columns always come back as strings.
     *
     * @param  array<string, mixed>|object  $row
     */
    public static function fromRow(array|object $row): self
    {
        /** @var array<string, mixed> $row */
        $row = (array) $row;

        $aggregateId = self::nullableString($row, 'aggregate_id');
        $aggregateSeq = self::nullableInt($row, 'aggregate_seq');

        if (($aggregateId === null) !== ($aggregateSeq === null)) {
            throw new UnexpectedValueException('aggregate_id and aggregate_seq must be set together.');
        }

        $status = MessageStatus::tryFrom(self::string($row, 'status'))
            ?? throw new UnexpectedValueException('Unknown outbox message status.');

        $attempts = self::int($row, 'attempts');
        if ($attempts < 0) {
            throw new UnexpectedValueException('attempts must not be negative.');
        }

        return new self(
            id: MessageId::fromString(self::string($row, 'id')),
            topic: self::string($row, 'topic'),
            type: self::string($row, 'type'),
            payload: self::jsonArray($row, 'payload'),
            headers: self::headers($row),
            aggregateId: $aggregateId,
            aggregateSeq: $aggregateSeq,
            status: $status,
            attempts: $attempts,
            createdAt: self::int($row, 'created_at'),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException("Outbox row has no valid [{$key}].");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function nullableString(array $row, string $key): ?string
    {
        return ($row[$key] ?? null) === null ? null : self::string($row, $key);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new UnexpectedValueException("Outbox row has no valid [{$key}].");
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function nullableInt(array $row, string $key): ?int
    {
        return ($row[$key] ?? null) === null ? null : self::int($row, $key);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<array-key, mixed>
     */
    private static function jsonArray(array $row, string $key): array
    {
        try {
            $decoded = json_decode(self::string($row, $key), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException("Outbox row has invalid JSON in [{$key}].", 0, $e);
        }

        if (! is_array($decoded)) {
            throw new UnexpectedValueException("Outbox row [{$key}] must be a JSON object or array.");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private static function headers(array $row): array
    {
        $headers = [];

        foreach (self::jsonArray($row, 'headers') as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                throw new UnexpectedValueException('Outbox headers must be a map of strings.');
            }

            $headers[$name] = $value;
        }

        return $headers;
    }
}
