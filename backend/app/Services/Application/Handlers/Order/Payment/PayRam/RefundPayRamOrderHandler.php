<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\PayRam;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\PayramPaymentDomainObject;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\Exceptions\RefundNotPossibleException;
use HiEvents\Mail\Order\OrderRefunded;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Domain\Order\OrderCancelService;
use HiEvents\Services\Domain\Order\PayRamOrderRefundService;
use HiEvents\Values\MoneyValue;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

class RefundPayRamOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly Mailer $mailer,
        private readonly OrderCancelService $orderCancelService,
        private readonly PayRamOrderRefundService $payRamOrderRefundService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws RefundNotPossibleException
     * @throws Throwable
     */
    public function handle(RefundOrderDTO $refundOrderDTO): OrderDomainObject
    {
        return $this->databaseManager->transaction(fn () => $this->refundOrder($refundOrderDTO));
    }

    /**
     * @throws RefundNotPossibleException
     */
    private function refundOrder(RefundOrderDTO $refundOrderDTO): OrderDomainObject
    {
        $order = $this->fetchOrder($refundOrderDTO->event_id, $refundOrderDTO->order_id);

        $this->validateRefundability($order, $refundOrderDTO->amount);

        if ($refundOrderDTO->cancel_order && ! $order->isOrderCancelled()) {
            $this->orderCancelService->cancelOrder($order);
        }

        $amount = MoneyValue::fromFloat($refundOrderDTO->amount, $order->getCurrency());

        $this->payRamOrderRefundService->refundOrder(
            order: $order,
            amount: $amount,
            transactionHash: $refundOrderDTO->refund_transaction_hash,
        );

        if ($refundOrderDTO->notify_buyer && $order->getEmail() !== null) {
            $this->notifyBuyer($order, $amount);
        }

        return $this->orderRepository->findById($order->getId());
    }

    private function fetchOrder(int $eventId, int $orderId): OrderDomainObject
    {
        $order = $this->orderRepository
            ->loadRelation(new Relationship(PayramPaymentDomainObject::class, name: 'payram_payment'))
            ->findFirstWhere(['event_id' => $eventId, 'id' => $orderId]);

        if (! $order) {
            throw new ResourceNotFoundException(__('Order :id not found for event :eventId', [
                'id' => $orderId,
                'eventId' => $eventId,
            ]));
        }

        return $order;
    }

    /**
     * @throws RefundNotPossibleException
     */
    private function validateRefundability(OrderDomainObject $order, float $amount): void
    {
        if ($order->getPaymentProvider() !== PaymentProviders::PAYRAM->value && $order->getPaymentProvider() !== PaymentProviders::PAYRAM->name) {
            throw new RefundNotPossibleException(__('This order was not paid with PayRam.'));
        }

        if ($order->getRefundStatus() === OrderRefundStatus::REFUND_PENDING->name) {
            throw new RefundNotPossibleException(
                __('There is already a refund pending for this order. Please wait for it to process before requesting another one.')
            );
        }

        if ($order->getRefundStatus() === OrderRefundStatus::REFUNDED->name) {
            throw new RefundNotPossibleException(__('This order has already been fully refunded.'));
        }

        if ($amount > $order->getTotalGross() - $order->getTotalRefunded()) {
            throw new RefundNotPossibleException(__('The refund amount cannot exceed the amount available to refund.'));
        }
    }

    private function notifyBuyer(OrderDomainObject $order, MoneyValue $amount): void
    {
        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(EventSettingDomainObject::class)
            ->findById($order->getEventId());

        $this->mailer
            ->to($order->getEmail())
            ->locale($order->getLocale())
            ->send(new OrderRefunded(
                order: $order,
                event: $event,
                organizer: $event->getOrganizer(),
                eventSettings: $event->getEventSettings(),
                refundAmount: $amount,
            ));
    }
}
