<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Support;

use InvalidArgumentException;
use JsonException;

/**
 * A validated message before it is stored. Shared by the database outbox and the fake,
 * so a test with Outbox::fake() rejects the same input as production.
 *
 * @internal
 */
final readonly class NewMessage
{
    public string $encodedPayload;

    public string $encodedHeaders;

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<array-key, mixed>  $headers
     */
    public function __construct(
        public string $topic,
        public string $type,
        public array $payload,
        public ?string $aggregateId,
        array $headers,
    ) {
        foreach (['topic' => $topic, 'type' => $type] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Outbox message {$field} must not be empty.");
            }
        }

        if ($aggregateId !== null && trim($aggregateId) === '') {
            throw new InvalidArgumentException('Outbox aggregateId must be null or a non-empty string.');
        }

        foreach ($headers as $name => $value) {
            if (! is_string($name) || $name === '' || ! is_string($value)) {
                throw new InvalidArgumentException('Outbox headers must be a map of non-empty names to strings.');
            }

            // ce_* carries the CloudEvents attributes; data and content-type are Redis Streams fields.
            $lower = strtolower($name);
            if (str_starts_with($lower, 'ce_') || $lower === 'data' || $lower === 'content-type') {
                throw new InvalidArgumentException("Outbox header name [{$name}] is reserved.");
            }
        }

        try {
            $this->encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            // An object even when empty, so consumers always get a map.
            $this->encodedHeaders = json_encode((object) $headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Outbox payload must be JSON-encodable: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        /** @var array<string, string> */
        return json_decode($this->encodedHeaders, true, 512, JSON_THROW_ON_ERROR);
    }
}
