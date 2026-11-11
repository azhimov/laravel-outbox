<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Transports;

use Azhimov\Outbox\Contracts\Transport;
use Azhimov\Outbox\Exceptions\TransportFailed;
use Azhimov\Outbox\Support\CloudEvent;
use Azhimov\Outbox\Support\OutboxMessage;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use LogicException;
use Predis\ClientInterface as PredisClient;
use Redis as PhpRedis;
use Throwable;

/**
 * Appends to a Redis Stream per topic (prefix + topic) with XADD. Fields, in order:
 * ce_* CloudEvents attributes, content-type, the message's own headers, data (payload).
 * Works with phpredis and predis through a Laravel Redis connection.
 */
final readonly class RedisStreamsTransport implements Transport
{
    public function __construct(
        private RedisFactory $redis,
        private ?string $connection,
        private string $prefix,
        /** Approximate MAXLEN per stream; null keeps every entry. */
        private ?int $maxLength,
        private string $source,
    ) {}

    public function publish(OutboxMessage $message): void
    {
        $event = CloudEvent::fromMessage($message, $this->source);
        $stream = $this->prefix.$event->topic;
        $fields = [
            ...$event->prefixedAttributes(),
            'content-type' => CloudEvent::CONTENT_TYPE,
            ...$event->headers,
            'data' => $event->data,
        ];

        try {
            $id = $this->add($stream, $fields);
        } catch (Throwable $e) {
            throw TransportFailed::because('Redis', $e->getMessage(), $e);
        }

        if (! is_string($id) || $id === '') {
            throw TransportFailed::because('Redis', "XADD to [{$stream}] returned no entry id");
        }
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function add(string $stream, array $fields): mixed
    {
        $client = $this->redis->connection($this->connection)->client();

        if ($client instanceof PhpRedis) {
            return $client->xAdd($stream, '*', $fields, $this->maxLength ?? 0, $this->maxLength !== null);
        }

        if ($client instanceof PredisClient) {
            $options = $this->maxLength === null ? [] : ['trim' => ['MAXLEN', '~', $this->maxLength]];

            return $client->xadd($stream, $fields, '*', $options);
        }

        throw new LogicException('The redis transport supports the phpredis and predis clients.');
    }
}
