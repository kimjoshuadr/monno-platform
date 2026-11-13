<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\PayRam;

use Brick\Money\Currency;
use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerPayramAccountDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\PayramPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\PayramPaymentDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\PayRamPaymentStatus;
use HiEvents\Exceptions\PayRam\PayRamConfigurationException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Repository\Interfaces\PayRamPaymentsRepositoryInterface;
use HiEvents\Services\Domain\Payment\PayRam\DTOs\CreatePayRamPaymentResponseDTO;
use HiEvents\Services\Domain\Payment\PayRam\PayRamCredentialResolver;
use HiEvents\Services\Domain\Payment\PayRam\PayRamProjectFeeService;
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
        private PayRamCredentialResolver $credentialResolver,
        private OrganizerPayRamAccountsRepositoryInterface $accountsRepository,
        private PayRamProjectFeeService $projectFeeService,
    ) {}

    /**
     * @throws UnauthorizedException
     * @throws ResourceConflictException
     * @throws PayRamConfigurationException
     */
    public function handle(string $orderShortId): CreatePayRamPaymentResponseDTO
    {
        $order = $this->orderRepository
            ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
            ->findByShortId($orderShortId);

        if (! $order || ! $this->sessionIdentifierService->verifySession($order->getSessionId())) {
            throw new UnauthorizedException(__('Sorry, we could not verify your session. Please create a new order.'));
        }

        if ($order->getStatus() !== OrderStatus::RESERVED->name || $order->isReservedOrderExpired()) {
            throw new ResourceConflictException(__('Sorry, this order has expired or is not in a valid state.'));
        }

        $this->assertPayRamIsEnabledForEvent($order->getEventId());

        [$amountInUsd, $payramFeeUsd, $fxRate, $feeRateBps] = $this->quote($order, $this->resolveProjectId($order));

        $this->assertInvoiceClearsTheFloor($amountInUsd);

        $expiresAt = $this->determineExpiry($order);
        $this->keepReservationAlive($order, $expiresAt);

        $existing = $this->findReusableSession($order->getId(), $expiresAt);
        if ($existing !== null) {
            $existingAmount = (float) $existing->getAmountInUsd();
            $existingFee = (float) ($existing->getPlatformFeeUsd() ?? 0);

            return new CreatePayRamPaymentResponseDTO(
                referenceId: $existing->getReferenceId(),
                checkoutUrl: (string) $existing->getCheckoutUrl(),
                amountInUsd: $existingAmount,
                payramFeeUsd: $existingFee,
                // Derive the rate from what was actually quoted, so a rate
                // change after the session was minted cannot mislabel it.
                feeRateBps: $existingAmount > 0 ? (int) round($existingFee / $existingAmount * 10000) : 0,
                orderAmount: (float) $existing->getOrderAmount(),
                orderCurrency: $existing->getOrderCurrency(),
                fxRate: (float) ($existing->getFxRate() ?? 1),
                expiresAt: $expiresAt->toIso8601String(),
            );
        }

        // Money moves under the organizer's own merchant account when they
        // have one; otherwise the shared instance key (their funds would land
        // in monno's wallet, which is why this is only a staging/QA fallback).
        $organizerId = $order->getEvent()?->getOrganizerId();
        $apiKey = $this->credentialResolver->forOrganizer($organizerId);

        $session = $this->payramClient->createPayment(
            customerEmail: $order->getEmail() ?? config('mail.from.address'),
            customerId: $order->getShortId(),
            amountInUsd: $amountInUsd,
            invoiceId: $order->getShortId(),
            expireAt: $expiresAt->format(DATE_ATOM),
            apiKey: $apiKey,
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
            PayramPaymentDomainObjectAbstract::PLATFORM_FEE_USD => $payramFeeUsd,
            PayramPaymentDomainObjectAbstract::STATUS => PayRamPaymentStatus::OPEN->value,
            PayramPaymentDomainObjectAbstract::EXPIRES_AT => $expiresAt->toDateTimeString(),
        ]);

        // Stamp the provider now, not only at settlement. The buyer is about to
        // be handed a crypto checkout, so the return page must be able to tell
        // this order is PayRam before it is paid — otherwise it asks Stripe,
        // which can never confirm a crypto order, and shows a false failure.
        $this->orderRepository->updateFromArray($order->getId(), [
            OrderDomainObjectAbstract::PAYMENT_PROVIDER => PaymentProviders::PAYRAM->value,
        ]);

        return new CreatePayRamPaymentResponseDTO(
            referenceId: $session->referenceId,
            checkoutUrl: $session->checkoutUrl,
            amountInUsd: $amountInUsd,
            payramFeeUsd: $payramFeeUsd,
            feeRateBps: $feeRateBps,
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
     * Refuse a crypto invoice so small that the rounding of the crypto amount
     * the buyer is shown can be a material fraction of the invoice.
     *
     * PayRam invoices in USD but the buyer pays in a coin, and its checkout
     * displays the coin amount at that coin's precision. At tiny amounts the
     * rounding is not cosmetic: a $0.17 invoice shown as "0.00006 ETH" arrives
     * as $0.1578, PayRam marks it PARTIALLY_FILLED, and a partial can never be
     * topped up or settled — the buyer's money is taken and no ticket is issued.
     * Better to not offer crypto than to take money that cannot settle.
     *
     * @throws PayRamConfigurationException
     */
    private function assertInvoiceClearsTheFloor(float $amountInUsd): void
    {
        $floor = (float) config('services.payram.min_invoice_usd', 0);

        if ($floor > 0 && $amountInUsd < $floor) {
            throw new PayRamConfigurationException(__(
                'Crypto payments are only available for orders of at least :amount, because smaller crypto amounts cannot be matched exactly. Please choose another payment method.',
                ['amount' => '$'.number_format($floor, 2)],
            ));
        }
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: int} [amountInUsd, payramFeeUsd, fxRate, feeRateBps]
     *
     * @throws PayRamConfigurationException
     */
    private function quote($order, ?int $projectId): array
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

        // PayRam's settlement fee is passed to the buyer: they pay the ticket
        // price grossed up by exactly what the sweep will take for PayRam.
        // Monno's operator fee is deliberately NOT grossed up — the organizer
        // bears it (see config/services.php).
        //
        // The rate is learned from the merchant's own sweeps where possible: the
        // platform fee is not exposed by any API, so the realised fee leg is the
        // only honest source. The configured value seeds a merchant with no
        // history yet.
        $feeBps = $this->projectFeeService->settlementFeeBps($projectId);
        if ($feeBps === 0) {
            return [round($ticketUsd, 2), 0.0, $fxRate, 0];
        }

        // The fee is a percentage of what gets swept, and what gets swept
        // includes the grossed-up amount, so the markup is ticket / (1 - fee) —
        // not ticket * (1 + fee), which would leave the organizer short by the
        // fee squared.
        $grossedUp = $ticketUsd / (1 - ($feeBps / 10000));

        // Round up to the cent so we never under-collect the fee.
        $amountInUsd = ceil($grossedUp * 100) / 100;

        return [$amountInUsd, round($amountInUsd - $ticketUsd, 2), $fxRate, $feeBps];
    }

    /**
     * The merchant's PayRam project, so the buyer's markup can be priced from
     * their own settlements. Null when there is no linked account — the fee
     * service then falls back to the configured rate.
     */
    private function resolveProjectId(OrderDomainObject $order): ?int
    {
        $organizerId = $order->getEvent()?->getOrganizerId();

        if ($organizerId === null) {
            return null;
        }

        $account = $this->accountsRepository->findFirstWhere([
            OrganizerPayramAccountDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        return $account?->getExternalPlatformId();
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
