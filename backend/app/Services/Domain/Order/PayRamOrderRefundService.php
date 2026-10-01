<?php

namespace HiEvents\Services\Domain\Order;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\Repository\Interfaces\OrderRefundRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsRefundService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use HiEvents\Values\MoneyValue;
use Illuminate\Support\Str;

class PayRamOrderRefundService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderRefundRepositoryInterface $orderRefundRepository,
        private readonly EventStatisticsRefundService $eventStatisticsRefundService,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
    ) {}

    public function refundOrder(OrderDomainObject $order, MoneyValue $amount, ?string $transactionHash = null): void
    {
        $refundedAmount = $amount->toFloat();

        $metadata = array_filter([
            'transaction_hash' => $transactionHash,
            'source_address' => $order->getPayramPayment()?->getSourceAddress(),
            'crypto_currency' => $order->getPayramPayment()?->getCurrency(),
            'crypto_network' => $order->getPayramPayment()?->getNetwork(),
            'payment_reference_id' => $order->getPayramPayment()?->getReferenceId(),
        ], static fn ($value) => $value !== null && $value !== '');

        $this->orderRefundRepository->create([
            'order_id' => $order->getId(),
            'payment_provider' => PaymentProviders::PAYRAM->value,
            'refund_id' => 'payram_'.Str::uuid()->toString(),
            'amount' => $refundedAmount,
            'currency' => $order->getCurrency(),
            'status' => 'succeeded',
            'metadata' => $metadata,
        ]);

        $this->orderRepository->increment(
            id: $order->getId(),
            column: OrderDomainObjectAbstract::TOTAL_REFUNDED,
            amount: $refundedAmount,
        );

        $refundStatus = $refundedAmount + $order->getTotalRefunded() >= $order->getTotalGross()
            ? OrderRefundStatus::REFUNDED->name
            : OrderRefundStatus::PARTIALLY_REFUNDED->name;

        $this->orderRepository->updateFromArray($order->getId(), [
            OrderDomainObjectAbstract::REFUND_STATUS => $refundStatus,
        ]);

        $this->eventStatisticsRefundService->updateForRefund($order, $amount);

        $this->domainEventDispatcherService->dispatch(
            new OrderEvent(
                type: DomainEventType::ORDER_REFUNDED,
                orderId: $order->getId(),
            ),
        );
    }
}
