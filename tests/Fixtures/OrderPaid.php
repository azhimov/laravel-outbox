<?php

declare(strict_types=1);

namespace Azhimov\Outbox\Tests\Fixtures;

use Azhimov\Outbox\Contracts\ShouldBeOutboxed;

final readonly class OrderPaid implements ShouldBeOutboxed
{
    public function __construct(public int $orderId, public string $amount) {}

    public function topic(): string
    {
        return 'orders';
    }

    public function type(): string
    {
        return 'order.paid';
    }

    public function payload(): array
    {
        return ['order_id' => $this->orderId, 'amount' => $this->amount];
    }

    public function aggregateId(): ?string
    {
        return "order-{$this->orderId}";
    }
}
