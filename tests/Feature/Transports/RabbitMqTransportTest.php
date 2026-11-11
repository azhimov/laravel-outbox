<?php

declare(strict_types=1);

use Azhimov\Outbox\Exceptions\TransportFailed;
use Azhimov\Outbox\Support\CloudEvent;
use Azhimov\Outbox\Transports\RabbitMqTransport;
use Azhimov\Outbox\Transports\TransportManager;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * @return list<AMQPMessage>
 */
function receivedFromRabbit(): array
{
    // A confirmed message is in the queue, but a second connection may see it a moment later.
    for ($try = 0; $try < 50; $try++) {
        $messages = [];
        while (($message = test()->channel->basic_get(test()->queue, true)) !== null) {
            $messages[] = $message;
        }

        if ($messages !== []) {
            return $messages;
        }

        usleep(20_000);
    }

    return [];
}

function rabbitToCloudEvent(AMQPMessage $message): CloudEvent
{
    $headers = $message->has('application_headers') ? $message->get('application_headers')->getNativeData() : [];

    return CloudEvent::fromPrefixed($message->getExchange(), array_map('strval', $headers), $message->getBody());
}

describe('RabbitMQ', function (): void {
    beforeEach(function (): void {
        $this->channel = null;
        $this->amqp = null;

        if (! rabbitMqAvailable()) {
            $this->markTestSkipped('set OUTBOX_TEST_RABBITMQ=1 with a running RabbitMQ');
        }

        $s = rabbitMqSettings();
        $this->amqp = new AMQPStreamConnection($s['host'], $s['port'], $s['user'], $s['password'], $s['vhost']);
        $this->channel = $this->amqp->channel();

        // Same declaration as the transport (durable topic exchange), plus a queue that sees everything.
        $this->channel->exchange_declare('orders', 'topic', false, true, false);
        [$this->queue] = $this->channel->queue_declare('', false, false, true, true);
        $this->channel->queue_bind($this->queue, 'orders', '#');

        config(['outbox.transports.rabbitmq' => [...$s, 'declare_exchanges' => true]]);
        $this->rabbit = RabbitMqTransport::fromConfig(config('outbox.transports.rabbitmq'), source: 'shop');
        $this->receivedEvents = [];
    });

    afterEach(function (): void {
        $this->channel?->close();
        $this->amqp?->close();
    });

    transportContract(
        fn () => test()->rabbit,
        function (): array {
            // Draining is destructive, so keep what this test has already received.
            test()->receivedEvents = [...test()->receivedEvents, ...array_map(rabbitToCloudEvent(...), receivedFromRabbit())];

            return test()->receivedEvents;
        },
    );

    it('publishes persistent messages with AMQP properties set', function (): void {
        $this->rabbit->publish(outboxMessage());

        [$message] = receivedFromRabbit();

        expect($message->get('content_type'))->toBe('application/json')
            ->and($message->get('delivery_mode'))->toBe(AMQPMessage::DELIVERY_MODE_PERSISTENT)
            ->and($message->get('message_id'))->toBe('0193a2c4-6f1e-7b3a-9c2d-1e2f3a4b5c6d')
            ->and($message->get('type'))->toBe('order.paid')
            ->and($message->get('app_id'))->toBe('shop')
            ->and($message->get('timestamp'))->toBe(1794477600)
            ->and($message->getRoutingKey())->toBe('order.paid')
            ->and($message->get('application_headers'))->toBeInstanceOf(AMQPTable::class);
    });

    it('fails when no queue is bound for the message', function (): void {
        $this->channel->exchange_declare('nowhere', 'topic', false, true, false);

        $this->rabbit->publish(outboxMessage(['topic' => 'nowhere']));
    })->throws(TransportFailed::class, 'unroutable');

    it('declares a missing exchange when asked to', function (): void {
        $this->channel->exchange_delete('invoices');

        $this->rabbit->publish(outboxMessage(['topic' => 'invoices']));
    })->throws(TransportFailed::class, 'unroutable');

    it('fails when the exchange does not exist and declaring is off', function (): void {
        $this->channel->exchange_delete('invoices');
        $transport = RabbitMqTransport::fromConfig([...rabbitMqSettings(), 'declare_exchanges' => false], source: 'shop');

        $transport->publish(outboxMessage(['topic' => 'invoices']));
    })->throws(TransportFailed::class);

    it('recovers after a failure by reconnecting', function (): void {
        $transport = RabbitMqTransport::fromConfig([...rabbitMqSettings(), 'declare_exchanges' => false], source: 'shop');

        try {
            $transport->publish(outboxMessage(['topic' => 'missing-exchange']));
        } catch (TransportFailed) {
        }

        $transport->publish(outboxMessage());

        expect(receivedFromRabbit())->toHaveCount(1);
    });

    it('reads a delivered message back with receive()', function (): void {
        $sent = outboxMessage(['headers' => ['tenant' => 'demo', 'trace' => 'abc']]);
        $this->rabbit->publish($sent);

        [$amqp] = receivedFromRabbit();
        $received = RabbitMqTransport::receive($amqp);

        expect($received->id)->toBe($sent->id->value)
            ->and($received->type)->toBe('order.paid')
            ->and($received->subject)->toBe('order-1001')
            ->and($received->source)->toBe('shop')
            ->and($received->time)->toBe('2026-11-12T10:00:00.123Z')
            ->and($received->topic)->toBe('orders')
            ->and($received->headers)->toBe(['tenant' => 'demo', 'trace' => 'abc'])
            ->and($received->body)->toBe($sent->body)
            ->and($received->payload)->toBe($sent->payload);
    });

    it('receives a message without an aggregate', function (): void {
        $this->rabbit->publish(outboxMessage(['aggregateId' => null, 'headers' => []]));

        [$amqp] = receivedFromRabbit();

        expect(RabbitMqTransport::receive($amqp)->subject)->toBeNull()
            ->and(RabbitMqTransport::receive($amqp)->headers)->toBe([]);
    });

    it('refuses a message that is not a CloudEvent', function (): void {
        $this->channel->basic_publish(new AMQPMessage('{"x":1}'), 'orders', 'order.paid');

        [$amqp] = receivedFromRabbit();

        RabbitMqTransport::receive($amqp);
    })->throws(UnexpectedValueException::class);

    it('is resolved by the transport manager', function (): void {
        config(['outbox.transport' => 'rabbitmq']);

        expect(app(TransportManager::class)->driver())->toBeInstanceOf(RabbitMqTransport::class);
    });
});

it('fails with a clear error when the broker is unreachable', function (): void {
    $transport = RabbitMqTransport::fromConfig(['host' => '127.0.0.1', 'port' => 1, 'connection_timeout' => 0.5], source: 'shop');

    $transport->publish(outboxMessage());
})->throws(TransportFailed::class, 'RabbitMQ');
