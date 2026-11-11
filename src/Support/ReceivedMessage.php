<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Support;

use JsonException;
use UnexpectedValueException;

/**
 * What a consumer-side handler gets: the CloudEvents attributes, headers and payload.
 */
final readonly class ReceivedMessage
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $source,
        public ?string $subject,
        public string $time,
        public string $topic,
        public array $headers,
        public string $body,
        public array $payload,
    ) {}

    public static function fromCloudEvent(CloudEvent $event): self
    {
        foreach (['id', 'type', 'source', 'time'] as $required) {
            if (($event->attributes[$required] ?? '') === '') {
                throw new UnexpectedValueException("Received message has no [{$required}] attribute.");
            }
        }

        try {
            $payload = json_decode($event->data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException('Received message data is not valid JSON.', 0, $e);
        }

        if (! is_array($payload)) {
            throw new UnexpectedValueException('Received message data must be a JSON object or array.');
        }

        return new self(
            id: $event->attributes['id'],
            type: $event->attributes['type'],
            source: $event->attributes['source'],
            subject: $event->attributes['subject'] ?? null,
            time: $event->attributes['time'],
            topic: $event->topic,
            headers: $event->headers,
            body: $event->data,
            payload: $payload,
        );
    }
}
