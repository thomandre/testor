<?php

declare(strict_types=1);

namespace App\Orders;

final class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PaymentGateway $payments,
    ) {
    }

    public function pay(int $orderId): string
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new \DomainException("Order $orderId not found");
        }
        if ($order->status !== OrderStatus::Pending) {
            return 'already processed';
        }

        return $this->payments->charge($order->amount + $this->payments->fee($order->amount)) ? 'paid' : 'declined';
    }

    public function label(int $orderId): string
    {
        return match ($this->orders->statusOf($orderId)) {
            OrderStatus::Pending => 'Awaiting payment',
            OrderStatus::Paid => 'Paid',
            OrderStatus::Cancelled => 'Cancelled',
        };
    }

    public function totalWithFee(float $amount): float
    {
        $fee = $this->payments->fee($amount);
        if ($fee > $amount) {
            throw new \LogicException('Fee exceeds amount');
        }

        return round($amount + $fee, 2);
    }
}
