<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Transports;

use Azhimov\Outbox\Contracts\Transport;
use Azhimov\Outbox\Exceptions\TransportFailed;
use Azhimov\Outbox\Support\CloudEvent;
use Azhimov\Outbox\Support\OutboxMessage;
use Azhimov\Outbox\Support\ReceivedMessage;
use Closure;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Publishes to RabbitMQ: exchange = message topic, routing key = message type.
 *
 * Returns only after the broker confirmed the message (publisher confirms) and routed it
 * to at least one queue (mandatory flag): an unroutable message is a failure, not a success.
 * Messages are persistent; CloudEvents attributes go into application headers as ce_*.
 */
final class RabbitMqTransport implements Transport
{
    private ?AbstractConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /** @var array<string, true> */
    private array $declared = [];

    /**
     * @param  Closure(): AbstractConnection  $connect
     */
    public function __construct(
        private readonly Closure $connect,
        private readonly string $source,
        private readonly bool $declareExchanges = true,
        private readonly string $exchangeType = 'topic',
        private readonly float $confirmTimeout = 5.0,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config  outbox.transports.rabbitmq
     */
    public static function fromConfig(array $config, string $source): self
    {
        $string = fn (string $key, string $default): string => is_string($config[$key] ?? null) && $config[$key] !== '' ? $config[$key] : $default;
        $number = fn (string $key, float $default): float => is_numeric($config[$key] ?? null) ? (float) $config[$key] : $default;

        $connect = fn (): AbstractConnection => new AMQPStreamConnection(
            host: $string('host', '127.0.0.1'),
            port: (int) $number('port', 5672),
            user: $string('user', 'guest'),
            password: $string('password', 'guest'),
            vhost: $string('vhost', '/'),
            connection_timeout: $number('connection_timeout', 3.0),
            read_write_timeout: $number('read_write_timeout', 3.0),
        );

        return new self(
            connect: $connect,
            source: $source,
            declareExchanges: ($config['declare_exchanges'] ?? true) !== false,
            exchangeType: $string('exchange_type', 'topic'),
            confirmTimeout: $number('confirm_timeout', 5.0),
        );
    }

    public function publish(OutboxMessage $message): void
    {
        $event = CloudEvent::fromMessage($message, $this->source);
        $returned = null;
        $nacked = false;

        try {
            $channel = $this->channel();
            $this->declare($channel, $event->topic);

            $channel->set_return_listener(function (int $code, string $text) use (&$returned): void {
                $returned = "{$code} {$text}";
            });
            $channel->set_nack_handler(function () use (&$nacked): void {
                $nacked = true;
            });

            $channel->basic_publish($this->amqpMessage($message, $event), $event->topic, $message->type, true);
            $channel->wait_for_pending_acks_returns($this->confirmTimeout);
        } catch (Throwable $e) {
            // The channel is unusable after a broker error; start clean next time.
            $this->disconnect();

            throw TransportFailed::because('RabbitMQ', $e->getMessage(), $e);
        }

        if ($returned !== null) {
            throw TransportFailed::because('RabbitMQ', "message is unroutable ({$returned}): no queue is bound to exchange [{$event->topic}] for [{$message->type}]");
        }

        if ($nacked) {
            throw TransportFailed::because('RabbitMQ', 'the broker rejected the message (nack)');
        }
    }

    /**
     * The consumer side: turns a message this transport published (consumed with
     * basic_consume / basic_get) back into a ReceivedMessage, ready for Inbox::handle().
     * topic = the exchange it was published to.
     *
     * @throws \UnexpectedValueException when the message carries no CloudEvents attributes
     */
    public static function receive(AMQPMessage $message): ReceivedMessage
    {
        $fields = [];
        $table = $message->has('application_headers') ? $message->get('application_headers') : null;

        foreach ($table instanceof AMQPTable ? $table->getNativeData() : [] as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $fields[$name] = (string) $value;
            }
        }

        return ReceivedMessage::fromCloudEvent(
            CloudEvent::fromPrefixed($message->getExchange() ?? '', $fields, $message->getBody()),
        );
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel !== null && $this->channel->is_open()) {
            return $this->channel;
        }

        $this->connection = ($this->connect)();
        $this->channel = $this->connection->channel();
        $this->channel->confirm_select();
        $this->declared = [];

        return $this->channel;
    }

    private function declare(AMQPChannel $channel, string $exchange): void
    {
        if (! $this->declareExchanges || isset($this->declared[$exchange])) {
            return;
        }

        // Durable, not auto-deleted: survives broker restarts like the messages do.
        $channel->exchange_declare($exchange, $this->exchangeType, false, true, false);
        $this->declared[$exchange] = true;
    }

    private function amqpMessage(OutboxMessage $message, CloudEvent $event): AMQPMessage
    {
        return new AMQPMessage($event->data, [
            'content_type' => CloudEvent::CONTENT_TYPE,
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id' => $message->id->value,
            'type' => $message->type,
            'app_id' => $this->source,
            'timestamp' => intdiv($message->createdAt, 1000),
            'application_headers' => new AMQPTable([...$event->prefixedAttributes(), ...$event->headers]),
        ]);
    }

    private function disconnect(): void
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (Throwable) {
            // Already broken: nothing left to close.
        }

        $this->channel = null;
        $this->connection = null;
    }
}
