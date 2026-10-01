<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\PayRam;

use Brick\Money\Currency;
use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\PayramPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\PayramPaymentDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\PayRamPaymentStatus;
use HiEvents\Exceptions\PayRam\PayRamConfigurationException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\PayRamPaymentsRepositoryInterface;
use HiEvents\Services\Domain\Payment\PayRam\DTOs\CreatePayRamPaymentResponseDTO;
use HiEvents\Services\Infrastructure\CurrencyConversion\CurrencyConversionClientInterface;
use HiEvents\Services\Infrastructure\CurrencyConversion\NoOpCurrencyConversionClient;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamClient;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;

/**
 * Creates a hosted PayRam checkout session for a reserved order.
 *
 * PayRam is USD-only, so the order total is converted at quote time and the
 * operator fee is grossed up onto the buyer's amount — that way the organizer
 * still receives exactly their sticker price after PayRam's on-chain fee split.
 */
readonly class CreatePayRamPaymentHandler
{
    /**
     * The payment link must outlive the order reservation, and the reservation
     * must outlive on-chain confirmations, otherwise we would accept money for
     * an order that has already been released.
     */
    private const MIN_PAYMENT_WINDOW_MINUTES = 20;

    private const CONFIRMATION_BUFFER_MINUTES = 15;

    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private EventSettingsRepositoryInterface $eventSettingsRepository,
        private PayRamPaymentsRepositoryInterface $payramPaymentsRepository,
        private PayRamClient $payramClient,
        private CheckoutSessionManagementService $sessionIdentifierService,
        private CurrencyConversionClientInterface $currencyConversionClient,
    ) {}

    /**
     * @throws UnauthorizedException
     * @throws ResourceConflictException
     * @throws PayRamConfigurationException
     */
    public function handle(string $orderShortId): CreatePayRamPaymentResponseDTO
    {
        $order = $this->orderRepository->findByShortId($orderShortId);

        if (! $order || ! $this->sessionIdentifierService->verifySession($order->getSessionId())) {
            throw new UnauthorizedException(__('Sorry, we could not verify your session. Please create a new order.'));
        }

        if ($order->getStatus() !== OrderStatus::RESERVED->name || $order->isReservedOrderExpired()) {
            throw new ResourceConflictException(__('Sorry, this order has expired or is not in a valid state.'));
        }

        $this->assertPayRamIsEnabledForEvent($order->getEventId());

        [$amountInUsd, $platformFeeUsd, $fxRate] = $this->quote($order);

        $expiresAt = $this->determineExpiry($order);
        $this->keepReservationAlive($order, $expiresAt);

        $existing = $this->findReusableSession($order->getId(), $expiresAt);
        if ($existing !== null) {
            return new CreatePayRamPaymentResponseDTO(
                referenceId: $existing->getReferenceId(),
                checkoutUrl: (string) $existing->getCheckoutUrl(),
                amountInUsd: (float) $existing->getAmountInUsd(),
                platformFeeUsd: (float) ($existing->getPlatformFeeUsd() ?? 0),
                orderAmount: (float) $existing->getOrderAmount(),
                orderCurrency: $existing->getOrderCurrency(),
                fxRate: (float) ($existing->getFxRate() ?? 1),
                expiresAt: $expiresAt->toIso8601String(),
            );
        }

        $session = $this->payramClient->createPayment(
            customerEmail: $order->getEmail() ?? config('mail.from.address'),
            customerId: $order->getShortId(),
            amountInUsd: $amountInUsd,
            invoiceId: $order->getShortId(),
            expireAt: $expiresAt->format(DATE_ATOM),
        );

        $this->payramPaymentsRepository->create([
            PayramPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
            PayramPaymentDomainObjectAbstract::REFERENCE_ID => $session->referenceId,
            PayramPaymentDomainObjectAbstract::INVOICE_ID => $order->getShortId(),
            PayramPaymentDomainObjectAbstract::CUSTOMER_ID => $order->getShortId(),
            PayramPaymentDomainObjectAbstract::CHECKOUT_URL => $session->checkoutUrl,
            PayramPaymentDomainObjectAbstract::AMOUNT_IN_USD => $amountInUsd,
            PayramPaymentDomainObjectAbstract::ORDER_CURRENCY => $order->getCurrency(),
            PayramPaymentDomainObjectAbstract::ORDER_AMOUNT => (float) $order->getTotalGross(),
            PayramPaymentDomainObjectAbstract::FX_RATE => $fxRate,
            PayramPaymentDomainObjectAbstract::PLATFORM_FEE_USD => $platformFeeUsd,
            PayramPaymentDomainObjectAbstract::STATUS => PayRamPaymentStatus::OPEN->value,
            PayramPaymentDomainObjectAbstract::EXPIRES_AT => $expiresAt->toDateTimeString(),
        ]);

        return new CreatePayRamPaymentResponseDTO(
            referenceId: $session->referenceId,
            checkoutUrl: $session->checkoutUrl,
            amountInUsd: $amountInUsd,
            platformFeeUsd: $platformFeeUsd,
            orderAmount: (float) $order->getTotalGross(),
            orderCurrency: $order->getCurrency(),
            fxRate: $fxRate,
            expiresAt: $expiresAt->toIso8601String(),
        );
    }

    /**
     * @throws UnauthorizedException
     */
    private function assertPayRamIsEnabledForEvent(int $eventId): void
    {
        $eventSettings = $this->eventSettingsRepository->findFirstWhere([
            EventSettingDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        $providers = $eventSettings === null ? [] : (array) $eventSettings->getPaymentProviders();

        if (! in_array(PaymentProviders::PAYRAM->value, $providers, true)) {
            throw new UnauthorizedException(__('Crypto payments are not enabled for this event'));
        }
    }

    /**
     * @return array{0: float, 1: float, 2: float} [amountInUsd, platformFeeUsd, fxRate]
     *
     * @throws PayRamConfigurationException
     */
    private function quote($order): array
    {
        $orderAmount = (float) $order->getTotalGross();
        $orderCurrency = $order->getCurrency();
        $fxRate = 1.0;

        if ($orderCurrency !== 'USD') {
            // Never silently charge the local amount as if it were dollars.
            if ($this->currencyConversionClient instanceof NoOpCurrencyConversionClient) {
                throw new PayRamConfigurationException(
                    __('Crypto payments are unavailable while the exchange-rate provider is not configured.'),
                );
            }

            $converted = $this->currencyConversionClient->convert(
                fromCurrency: Currency::of($orderCurrency),
                toCurrency: Currency::of('USD'),
                amount: $orderAmount,
            );

            $ticketUsd = $converted->toFloat();
            if ($ticketUsd <= 0) {
                throw new PayRamConfigurationException(__('Could not convert this order total to USD.'));
            }

            $fxRate = $ticketUsd / $orderAmount;
        } else {
            $ticketUsd = $orderAmount;
        }

        $feeBps = max(0, (int) config('services.payram.fee_bps', 250));
        if ($feeBps === 0) {
            return [round($ticketUsd, 2), 0.0, $fxRate];
        }

        // Gross up so that, after PayRam takes feeBps on-chain, the organizer
        // is left with exactly the ticket price.
        $grossedUp = $ticketUsd / (1 - ($feeBps / 10000));

        // Round up to the cent so we never under-collect the fee.
        $amountInUsd = ceil($grossedUp * 100) / 100;

        return [$amountInUsd, round($amountInUsd - $ticketUsd, 2), $fxRate];
    }

    private function determineExpiry($order): Carbon
    {
        $now = Carbon::now();
        $reservedUntil = $order->getReservedUntil();

        $earliest = $now->copy()->addMinutes(self::MIN_PAYMENT_WINDOW_MINUTES);

        if ($reservedUntil === null) {
            return $earliest;
        }

        $reservedAt = Carbon::parse($reservedUntil);

        return $reservedAt->greaterThan($earliest) ? $reservedAt : $earliest;
    }

    /**
     * Stretch the reservation past the payment window so a confirmed payment
     * always lands on an order we still hold inventory for.
     */
    private function keepReservationAlive($order, Carbon $expiresAt): void
    {
        $requiredUntil = $expiresAt->copy()->addMinutes(self::CONFIRMATION_BUFFER_MINUTES);
        $reservedUntil = $order->getReservedUntil();

        if ($reservedUntil !== null && ! Carbon::parse($reservedUntil)->lessThan($requiredUntil)) {
            return;
        }

        $this->orderRepository->updateFromArray($order->getId(), [
            OrderDomainObjectAbstract::RESERVED_UNTIL => $requiredUntil->toDateTimeString(),
        ]);
    }

    /**
     * Reuse a still-live session instead of minting a new reference on every
     * page load (PayRam de-links the previous one when a newer one exists).
     */
    private function findReusableSession(int $orderId, Carbon $expiresAt): ?PayramPaymentDomainObject
    {
        $sessions = $this->payramPaymentsRepository->findWhere([
            PayramPaymentDomainObjectAbstract::ORDER_ID => $orderId,
            PayramPaymentDomainObjectAbstract::STATUS => PayRamPaymentStatus::OPEN->value,
        ], orderAndDirections: [new OrderAndDirection('id', OrderAndDirection::DIRECTION_DESC)], limit: 5);

        foreach ($sessions as $session) {
            $sessionExpiry = $session->getExpiresAt();
            $checkoutUrl = $session->getCheckoutUrl();

            if ($sessionExpiry === null || $checkoutUrl === null) {
                continue;
            }

            // Only reuse a link that is still alive and will not lapse before
            // the window we need this time round.
            if (Carbon::parse($sessionExpiry)->greaterThanOrEqualTo($expiresAt)) {
                return $session;
            }
        }

        return null;
    }
}
