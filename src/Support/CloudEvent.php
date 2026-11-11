<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Support;

use InvalidArgumentException;

/**
 * A message in CloudEvents 1.0 binary mode: the payload JSON is the data as is,
 * the attributes travel next to it (as ce_* headers or stream fields).
 *
 * @see https://github.com/cloudevents/spec/blob/v1.0.2/cloudevents/spec.md
 */
final readonly class CloudEvent
{
    public const PREFIX = 'ce_';

    public const CONTENT_TYPE = 'application/json';

    /**
     * @param  array<string, string>  $attributes  CloudEvents context attributes, without prefix
     * @param  array<string, string>  $headers  the message's own headers
     */
    public function __construct(
        public string $topic,
        public array $attributes,
        public array $headers,
        public string $data,
    ) {}

    public static function fromMessage(OutboxMessage $message, string $source): self
    {
        if (trim($source) === '') {
            throw new InvalidArgumentException('CloudEvents source must not be empty.');
        }

        $attributes = [
            'specversion' => '1.0',
            'id' => $message->id->value,
            'type' => $message->type,
            'source' => $source,
        ];

        if ($message->aggregateId !== null) {
            $attributes['subject'] = $message->aggregateId;
        }

        $attributes['time'] = self::rfc3339($message->createdAt);
        $attributes['datacontenttype'] = self::CONTENT_TYPE;

        return new self($message->topic, $attributes, $message->headers, $message->body);
    }

    /**
     * Rebuilds an event from ce_* headers (or stream fields) plus the message's own headers.
     *
     * @param  array<string, string>  $fields
     */
    public static function fromPrefixed(string $topic, array $fields, string $data): self
    {
        $known = ['specversion', 'id', 'type', 'source', 'subject', 'time'];
        $attributes = [];
        $headers = [];

        foreach ($fields as $name => $value) {
            if (str_starts_with($name, self::PREFIX)) {
                $attributes[substr($name, strlen(self::PREFIX))] = $value;
            } else {
                $headers[$name] = $value;
            }
        }

        $ordered = [];
        foreach ($known as $name) {
            if (isset($attributes[$name])) {
                $ordered[$name] = $attributes[$name];
            }
        }
        $ordered['datacontenttype'] = self::CONTENT_TYPE;

        return new self($topic, $ordered, $headers, $data);
    }

    /**
     * Attributes as ce_* names. datacontenttype is left out: in binary mode it is
     * the transport's own content type (AMQP content_type, a content-type field).
     *
     * @return array<string, string>
     */
    public function prefixedAttributes(): array
    {
        $prefixed = [];

        foreach ($this->attributes as $name => $value) {
            if ($name !== 'datacontenttype') {
                $prefixed[self::PREFIX.$name] = $value;
            }
        }

        return $prefixed;
    }

    private static function rfc3339(int $milliseconds): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($milliseconds, 1000))
            .sprintf('.%03dZ', $milliseconds % 1000);
    }
}
