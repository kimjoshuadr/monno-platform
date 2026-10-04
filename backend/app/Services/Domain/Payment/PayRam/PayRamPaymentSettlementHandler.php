<?php

namespace HiEvents\Services\Domain\Payment\PayRam;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\PayramPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\PayramPaymentDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderApplicationFeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\PayRamPaymentStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Exceptions\CannotAcceptPaymentException;
use HiEvents\Helper\Currency;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\PayRamPaymentsRepositoryInterface;
use HiEvents\Services\Domain\Order\OccurrenceStatusValidator;
use HiEvents\Services\Domain\Order\OrderApplicationFeeService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

/**
 * Marks an order paid once PayRam reports the payment as filled.
 *
 * Mirrors Stripe's PaymentIntentSucceededHandler: same order/attendee/quantity
 * transitions and the same domain events, so emails, invoices and outgoing
 * webhooks behave identically for crypto orders.
 */
class PayRamPaymentSettlementHandler
{
    public function __construct(
        private readonly PayRamPaymentsRepositoryInterface $payramPaymentsRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly AffiliateRepositoryInterface $affiliateRepository,
        private readonly ProductQuantityUpdateService $quantityUpdateService,
        private readonly OrderApplicationFeeService $orderApplicationFeeService,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
        private readonly OccurrenceStatusValidator $occurrenceStatusValidator,
        private readonly DatabaseManager $databaseManager,
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws CannotAcceptPaymentException
     */
    public function handle(array $payload): void
    {
        $referenceId = (string) ($payload['reference_id'] ?? '');

        if ($referenceId === '') {
            return;
        }

        $cacheKey = 'payram_settled_'.$referenceId;
        if ($this->cache->has($cacheKey)) {
            $this->logger->info('PayRam settlement already handled', ['reference_id' => $referenceId]);

            return;
        }

        $result = $this->databaseManager->transaction(function () use ($payload, $referenceId) {
            $payment = $this->payramPaymentsRepository
                ->loadRelation(new Relationship(OrderDomainObject::class, name: 'order', nested: [
                    new Relationship(OrderItemDomainObject::class),
                ]))
                ->findFirstWhere([
                    PayramPaymentDomainObjectAbstract::REFERENCE_ID => $referenceId,
                ]);

            if ($payment === null) {
                $this->logger->error('Received a PayRam settlement for an unknown payment', [
                    'reference_id' => $referenceId,
                ]);

                return null;
            }

            $status = PayRamPaymentStatus::tryFrom((string) ($payload['status'] ?? ''));
            if ($status === null || ! $status->isSettled()) {
                return null;
            }

            $order = $payment->getOrder();
            if ($order === null) {
                $this->logger->error('PayRam payment has no order attached', ['reference_id' => $referenceId]);

                return null;
            }

            if ($order->getStatus() === OrderStatus::COMPLETED->name) {
                $this->logger->info('Order already completed for PayRam payment', [
                    'reference_id' => $referenceId,
                    'order_id' => $order->getId(),
                ]);

                return null;
            }

            $this->validateOrderIsSettleable($order, $payment);

            $this->recordFill($payment, $payload, $status);

            $updatedOrder = $this->orderRepository
                ->loadRelation(OrderItemDomainObject::class)
                ->updateFromArray($order->getId(), [
                    OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::PAYMENT_RECEIVED->name,
                    OrderDomainObjectAbstract::STATUS => OrderStatus::COMPLETED->name,
                    OrderDomainObjectAbstract::PAYMENT_PROVIDER => PaymentProviders::PAYRAM->value,
                ]);

            if ($updatedOrder->getAffiliateId()) {
                $this->affiliateRepository->incrementSales(
                    affiliateId: $updatedOrder->getAffiliateId(),
                    amount: $updatedOrder->getTotalGross(),
                );
            }

            $this->updateAttendeeStatuses($updatedOrder);
            $this->quantityUpdateService->updateQuantitiesFromOrder($updatedOrder);

            $eventSettings = $this->eventSettingsRepository->findFirstWhere([
                EventSettingDomainObjectAbstract::EVENT_ID => $updatedOrder->getEventId(),
            ]);

            $this->recordApplicationFee($payment, $updatedOrder);

            return ['order' => $updatedOrder, 'invoicing' => (bool) $eventSettings?->getEnableInvoicing()];
        });

        if ($result === null) {
            return;
        }

        $this->cache->put($cacheKey, true, 3600);

        $this->logger->info('PayRam payment settled', [
            'reference_id' => $referenceId,
            'order_id' => $result['order']->getId(),
        ]);

        event(new OrderStatusChangedEvent($result['order'], createInvoice: $result['invoicing']));

        $this->domainEventDispatcherService->dispatch(
            new OrderEvent(
                type: DomainEventType::ORDER_CREATED,
                orderId: $result['order']->getId(),
            ),
        );
    }

    /**
     * @throws CannotAcceptPaymentException
     */
    private function validateOrderIsSettleable(OrderDomainObject $order, PayramPaymentDomainObject $payment): void
    {
        if (in_array($order->getStatus(), [
            OrderStatus::CANCELLED->name,
            OrderStatus::ABANDONED->name,
            OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
        ], true)) {
            throw new CannotAcceptPaymentException(__(
                'PayRam payment was received, but the order is no longer valid. Order: :id',
                ['id' => $order->getId()],
            ));
        }

        $paymentStatus = $order->getPaymentStatus();
        if ($paymentStatus !== null && ! in_array($paymentStatus, [
            OrderPaymentStatus::AWAITING_PAYMENT->name,
            OrderPaymentStatus::PAYMENT_FAILED->name,
        ], true)) {
            throw new CannotAcceptPaymentException(__(
                'PayRam payment was received, but the order is not awaiting payment. Order: :id',
                ['id' => $order->getId()],
            ));
        }

        if ($this->occurrenceStatusValidator->findBlockingOccurrence($order) !== null) {
            throw new CannotAcceptPaymentException(__(
                'PayRam payment was received, but the event date is no longer available. Order: :id',
                ['id' => $order->getId()],
            ));
        }

        // Reservations are never released automatically, so a late payment for
        // an expired reservation can still be honoured — but it is worth knowing
        // about, because the buyer paid after their window closed.
        if ($order->isReservedOrderExpired()) {
            $this->logger->warning('Settling a PayRam payment against an expired reservation', [
                'reference_id' => $payment->getReferenceId(),
                'order_id' => $order->getId(),
                'reserved_until' => $order->getReservedUntil(),
            ]);
        }
    }

    private function recordFill(PayramPaymentDomainObject $payment, array $payload, PayRamPaymentStatus $status): void
    {
        $paymentInfo = $payload['payment_info'] ?? null;
        $firstDeposit = is_array($paymentInfo) && isset($paymentInfo[0]) && is_array($paymentInfo[0])
            ? $paymentInfo[0]
            : [];

        $this->payramPaymentsRepository->updateWhere(
            attributes: array_filter([
                PayramPaymentDomainObjectAbstract::STATUS => $status->value,
                PayramPaymentDomainObjectAbstract::FILLED_AMOUNT => isset($payload['filled_amount']) ? (float) $payload['filled_amount'] : null,
                PayramPaymentDomainObjectAbstract::FILLED_AMOUNT_IN_USD => isset($payload['filled_amount_in_usd']) ? (float) $payload['filled_amount_in_usd'] : null,
                PayramPaymentDomainObjectAbstract::CURRENCY => $payload['currency'] ?? null,
                PayramPaymentDomainObjectAbstract::NETWORK => $payload['network'] ?? $payload['blockchain'] ?? null,
                PayramPaymentDomainObjectAbstract::SOURCE_ADDRESS => $firstDeposit['source_address'] ?? null,
                PayramPaymentDomainObjectAbstract::DESTINATION_ADDRESS => $firstDeposit['destination_address'] ?? null,
                PayramPaymentDomainObjectAbstract::TRANSACTION_HASH => $firstDeposit['transaction_hash'] ?? null,
                PayramPaymentDomainObjectAbstract::CONFIRMATION_CURRENT => isset($payload['confirmation_current']) ? (int) $payload['confirmation_current'] : null,
                PayramPaymentDomainObjectAbstract::CONFIRMATION_REQUIRED => isset($payload['confirmation_required']) ? (int) $payload['confirmation_required'] : null,
                PayramPaymentDomainObjectAbstract::PAYMENT_INFO => $paymentInfo,
                PayramPaymentDomainObjectAbstract::LAST_WEBHOOK_PAYLOAD => $payload,
                PayramPaymentDomainObjectAbstract::PAID_AT => now()->toDateTimeString(),
            ], static fn ($value) => $value !== null),
            where: [
                PayramPaymentDomainObjectAbstract::REFERENCE_ID => $payment->getReferenceId(),
            ],
        );
    }

    private function updateAttendeeStatuses(OrderDomainObject $updatedOrder): void
    {
        $this->attendeeRepository->updateWhere(
            attributes: [
                'status' => AttendeeStatus::ACTIVE->name,
            ],
            where: [
                'order_id' => $updatedOrder->getId(),
                'status' => AttendeeStatus::AWAITING_PAYMENT->name,
            ],
        );
    }

    /**
     * The platform fee was charged to the buyer on top of the ticket price, so
     * record it against the order in the order's own currency — keeping the
     * application-fee reporting identical across Stripe, offline and PayRam.
     */
    private function recordApplicationFee(PayramPaymentDomainObject $payment, OrderDomainObject $updatedOrder): void
    {
        // What we record here is Monno's own revenue: the operator fee taken
        // on-chain at sweep time. The stored platform_fee_usd is the *buyer's*
        // markup, which pays PayRam's settlement fee — a different party's cut.
        // The two are only equal while both rates happen to match, so derive
        // ours from the operator rate rather than reusing the buyer's markup.
        $operatorBps = max(0, (int) config('services.payram.operator_fee_bps', 0));
        if ($operatorBps === 0) {
            return;
        }

        $feeUsd = (float) $payment->getAmountInUsd() * $operatorBps / 10000;
        if ($feeUsd <= 0) {
            return;
        }

        $fxRate = (float) ($payment->getFxRate() ?? 0);

        $feeInOrderCurrency = $updatedOrder->getCurrency() === 'USD'
            ? $feeUsd
            : ($fxRate > 0 ? $feeUsd / $fxRate : 0.0);

        if ($feeInOrderCurrency <= 0) {
            return;
        }

        $isZeroDecimal = Currency::isZeroDecimalCurrency($updatedOrder->getCurrency());
        $minorUnits = $isZeroDecimal ? (int) round($feeInOrderCurrency) : (int) round($feeInOrderCurrency * 100);

        $this->orderApplicationFeeService->createOrderApplicationFee(
            orderId: $updatedOrder->getId(),
            applicationFeeAmountMinorUnit: $minorUnits,
            orderApplicationFeeStatus: OrderApplicationFeeStatus::PAID,
            paymentMethod: PaymentProviders::PAYRAM,
            currency: $updatedOrder->getCurrency(),
        );
    }
}
