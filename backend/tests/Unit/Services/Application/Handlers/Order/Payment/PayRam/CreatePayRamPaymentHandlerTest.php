<?php

namespace Tests\Unit\Services\Application\Handlers\Order\Payment\PayRam;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\PayramPaymentDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\PayRam\PayRamConfigurationException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Repository\Interfaces\PayRamPaymentsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\PayRam\CreatePayRamPaymentHandler;
use HiEvents\Services\Domain\Payment\PayRam\DTOs\PayRamPaymentSessionDTO;
use HiEvents\Services\Domain\Payment\PayRam\PayRamCredentialResolver;
use HiEvents\Services\Infrastructure\CurrencyConversion\CurrencyConversionClientInterface;
use HiEvents\Services\Infrastructure\CurrencyConversion\NoOpCurrencyConversionClient;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamClient;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use HiEvents\Values\MoneyValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class CreatePayRamPaymentHandlerTest extends TestCase
{
    private OrderRepositoryInterface $orderRepository;

    private EventSettingsRepositoryInterface $eventSettingsRepository;

    private PayRamPaymentsRepositoryInterface $payramPaymentsRepository;

    private PayRamClient $payramClient;

    private CheckoutSessionManagementService $sessionService;

    private CurrencyConversionClientInterface $currencyConversionClient;

    private OrganizerPayRamAccountsRepositoryInterface $accountsRepository;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payram.enabled' => true,
            'services.payram.base_url' => 'https://pay.monno.io',
            'services.payram.api_key' => 'key',
            'services.payram.fee_bps' => 250,
            'mail.from.address' => 'no-reply@monno.io',
        ]);

        $this->orderRepository = m::mock(OrderRepositoryInterface::class);
        $this->eventSettingsRepository = m::mock(EventSettingsRepositoryInterface::class);
        $this->payramPaymentsRepository = m::mock(PayRamPaymentsRepositoryInterface::class);
        $this->payramClient = m::mock(PayRamClient::class);
        $this->sessionService = m::mock(CheckoutSessionManagementService::class);
        $this->currencyConversionClient = m::mock(CurrencyConversionClientInterface::class);
        $this->accountsRepository = m::mock(OrganizerPayRamAccountsRepositoryInterface::class);
        // No per-organizer merchant account → the shared instance key is used.
        $this->accountsRepository->shouldReceive('findFirstWhere')->andReturn(null);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    private function makeOrder(
        string $currency = 'PHP',
        float $totalGross = 1099.00,
        string $status = OrderStatus::RESERVED->name,
        ?string $reservedUntil = null,
        ?string $sessionId = 'session-abc',
    ): OrderDomainObject {
        $order = new OrderDomainObject;
        $order->setId(77)
            ->setEventId(5)
            ->setShortId('ORDSHORT')
            ->setTotalGross($totalGross)
            ->setCurrency($currency)
            ->setStatus($status)
            ->setPaymentStatus(OrderPaymentStatus::AWAITING_PAYMENT->name)
            ->setReservedUntil($reservedUntil ?? Carbon::now()->addMinutes(30)->toDateTimeString())
            ->setSessionId($sessionId)
            ->setEmail('buyer@example.com');

        return $order;
    }

    private function makeSettings(array $providers): EventSettingDomainObject
    {
        $settings = new EventSettingDomainObject;
        $settings->setEventId(5)->setPaymentProviders($providers);

        return $settings;
    }

    private function handler(): CreatePayRamPaymentHandler
    {
        return new CreatePayRamPaymentHandler(
            orderRepository: $this->orderRepository,
            eventSettingsRepository: $this->eventSettingsRepository,
            payramPaymentsRepository: $this->payramPaymentsRepository,
            payramClient: $this->payramClient,
            sessionIdentifierService: $this->sessionService,
            currencyConversionClient: $this->currencyConversionClient,
            credentialResolver: new PayRamCredentialResolver($this->accountsRepository),
        );
    }

    public function test_it_refuses_to_quote_when_no_exchange_rate_provider_is_configured(): void
    {
        // No OPEN_EXCHANGE_RATES_APP_ID means the NoOp client is bound, which
        // would otherwise pass 1099.00 straight through as 1099.00 USD.
        $order = $this->makeOrder(currency: 'PHP', totalGross: 1099.00);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findByShortId')->andReturn($order);
        $this->sessionService->shouldReceive('verifySession')->andReturn(true);
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->makeSettings([PaymentProviders::PAYRAM->value]));

        $handler = new CreatePayRamPaymentHandler(
            orderRepository: $this->orderRepository,
            eventSettingsRepository: $this->eventSettingsRepository,
            payramPaymentsRepository: $this->payramPaymentsRepository,
            payramClient: $this->payramClient,
            sessionIdentifierService: $this->sessionService,
            currencyConversionClient: new NoOpCurrencyConversionClient(app(LoggerInterface::class)),
            credentialResolver: new PayRamCredentialResolver($this->accountsRepository),
        );

        $this->expectException(PayRamConfigurationException::class);
        $handler->handle('ORDSHORT');
    }

    public function test_it_allows_a_usd_order_without_an_exchange_rate_provider(): void
    {
        $order = $this->makeOrder(currency: 'USD', totalGross: 25.00);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findByShortId')->andReturn($order);
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($order);
        $this->sessionService->shouldReceive('verifySession')->andReturn(true);
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->makeSettings([PaymentProviders::PAYRAM->value]));
        $this->payramPaymentsRepository->shouldReceive('findWhere')->andReturn(new Collection);
        $this->payramPaymentsRepository->shouldReceive('create')->andReturn(new PayramPaymentDomainObject);
        $this->payramClient->shouldReceive('createPayment')
            ->andReturn(new PayRamPaymentSessionDTO(
                referenceId: 'ref-1',
                checkoutUrl: 'https://pay.monno.io/payments?reference_id=ref-1',
                host: 'https://pay.monno.io',
            ));

        $response = $this->handler()->handle('ORDSHORT');

        $this->assertSame('ref-1', $response->referenceId);
        $this->assertSame(25.00, $response->orderAmount);
        // 25.00 / (1 - 0.025) = 25.6410... -> rounds up to the cent
        $this->assertSame(25.65, $response->amountInUsd);
        $this->assertSame(0.65, $response->platformFeeUsd);
    }

    public function test_it_stamps_the_order_as_payram_when_the_session_is_created(): void
    {
        // The provider must be known before payment, not only at settlement:
        // the return page uses it to avoid asking Stripe about a crypto order,
        // which can never confirm and used to show a false failure.
        $order = $this->makeOrder(currency: 'USD', totalGross: 25.00);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findByShortId')->andReturn($order);

        // updateFromArray is also used to stretch the reservation, so record
        // every call and assert the provider stamp is among them.
        $updates = [];
        $this->orderRepository->shouldReceive('updateFromArray')
            ->andReturnUsing(function (int $id, array $attributes) use (&$updates, $order) {
                $updates[] = $attributes;

                return $order;
            });

        $this->sessionService->shouldReceive('verifySession')->andReturn(true);
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->makeSettings([PaymentProviders::PAYRAM->value]));
        $this->payramPaymentsRepository->shouldReceive('findWhere')->andReturn(new Collection);
        $this->payramPaymentsRepository->shouldReceive('create')->andReturn(new PayramPaymentDomainObject);
        $this->payramClient->shouldReceive('createPayment')
            ->andReturn(new PayRamPaymentSessionDTO(
                referenceId: 'ref-1',
                checkoutUrl: 'https://pay.monno.io/payments?reference_id=ref-1',
                host: 'https://pay.monno.io',
            ));

        $this->handler()->handle('ORDSHORT');

        $providers = array_filter(
            array_map(fn (array $attributes) => $attributes[OrderDomainObjectAbstract::PAYMENT_PROVIDER] ?? null, $updates)
        );

        $this->assertContains(
            PaymentProviders::PAYRAM->value,
            $providers,
            'Creating a PayRam session must stamp the order as PayRam.'
        );
    }

    public function test_it_grosses_the_fee_up_to_the_cent_so_we_never_under_collect(): void
    {
        $order = $this->makeOrder(currency: 'USD', totalGross: 100.00);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findByShortId')->andReturn($order);
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($order);
        $this->sessionService->shouldReceive('verifySession')->andReturn(true);
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->makeSettings([PaymentProviders::PAYRAM->value]));
        $this->payramPaymentsRepository->shouldReceive('findWhere')->andReturn(new Collection);
        $this->payramPaymentsRepository->shouldReceive('create')->andReturn(new PayramPaymentDomainObject);
        $this->payramClient->shouldReceive('createPayment')
            ->andReturn(new PayRamPaymentSessionDTO('ref-2', 'https://pay.monno.io/payments?reference_id=ref-2', ''));

        $response = $this->handler()->handle('ORDSHORT');

        // 100 / 0.975 = 102.5641... -> 102.57
        $this->assertSame(102.57, $response->amountInUsd);
        $this->assertSame(2.57, $response->platformFeeUsd);

        // After PayRam takes 2.5% of what the buyer paid, the organizer is left
        // with at least the ticket price.
        $this->assertGreaterThanOrEqual(100.00, $response->amountInUsd * 0.975);
    }

    public function test_it_converts_the_order_total_before_quoting(): void
    {
        $order = $this->makeOrder(currency: 'PHP', totalGross: 1099.00);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findByShortId')->andReturn($order);
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($order);
        $this->sessionService->shouldReceive('verifySession')->andReturn(true);
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->makeSettings([PaymentProviders::PAYRAM->value]));
        $this->currencyConversionClient->shouldReceive('convert')
            ->withArgs(fn ($from, $to, $amount) => $from->getCurrencyCode() === 'PHP'
                && $to->getCurrencyCode() === 'USD'
                && $amount === 1099.00)
            ->andReturn(MoneyValue::fromFloat(19.30, 'USD'));
        $this->payramPaymentsRepository->shouldReceive('findWhere')->andReturn(new Collection);
        $this->payramPaymentsRepository->shouldReceive('create')->andReturn(new PayramPaymentDomainObject);
        $this->payramClient->shouldReceive('createPayment')
            ->andReturn(new PayRamPaymentSessionDTO('ref-3', 'https://pay.monno.io/payments?reference_id=ref-3', ''));

        $response = $this->handler()->handle('ORDSHORT');

        // 19.30 / (1 - 0.025) = 19.7948... -> rounds up to 19.80
        $this->assertSame(19.80, $response->amountInUsd);
        $this->assertSame(0.50, $response->platformFeeUsd);
        $this->assertSame('PHP', $response->orderCurrency);
        $this->assertSame(1099.00, $response->orderAmount);
        $this->assertSame(0.017561, round($response->fxRate, 6));

        // After PayRam takes its 2.5% on-chain, the organizer is still left
        // with at least the ticket price they advertised.
        $this->assertGreaterThanOrEqual(19.30, $response->amountInUsd * 0.975);
    }

    public function test_it_rejects_an_order_whose_session_cannot_be_verified(): void
    {
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findByShortId')->andReturn($this->makeOrder());
        $this->sessionService->shouldReceive('verifySession')->andReturn(false);

        $this->expectException(UnauthorizedException::class);
        $this->handler()->handle('ORDSHORT');
    }

    public function test_it_rejects_an_order_when_crypto_is_not_enabled_for_the_event(): void
    {
        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findByShortId')->andReturn($this->makeOrder());
        $this->sessionService->shouldReceive('verifySession')->andReturn(true);
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->makeSettings([PaymentProviders::OFFLINE->value]));

        $this->expectException(UnauthorizedException::class);
        $this->handler()->handle('ORDSHORT');
    }

    public function test_it_rejects_an_expired_order(): void
    {
        $order = $this->makeOrder(reservedUntil: Carbon::now()->subMinute()->toDateTimeString());

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findByShortId')->andReturn($order);
        $this->sessionService->shouldReceive('verifySession')->andReturn(true);

        $this->expectException(ResourceConflictException::class);
        $this->handler()->handle('ORDSHORT');
    }

    public function test_it_rejects_a_completed_order(): void
    {
        $order = $this->makeOrder(status: OrderStatus::COMPLETED->name);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findByShortId')->andReturn($order);
        $this->sessionService->shouldReceive('verifySession')->andReturn(true);

        $this->expectException(ResourceConflictException::class);
        $this->handler()->handle('ORDSHORT');
    }

    public function test_it_refuses_an_invoice_below_the_crypto_floor(): void
    {
        // $0.17 is the real incident: the buyer sent the displayed 0.00006 ETH
        // and was judged short, and a partial can never settle. Below the floor
        // we must not create the invoice at all.
        config(['services.payram.min_invoice_usd' => 1.00]);

        $order = $this->makeOrder(currency: 'USD', totalGross: 0.17);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findByShortId')->andReturn($order);
        $this->sessionService->shouldReceive('verifySession')->andReturn(true);
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->makeSettings([PaymentProviders::PAYRAM->value]));

        // No invoice may be created.
        $this->payramClient->shouldReceive('createPayment')->never();
        $this->payramPaymentsRepository->shouldReceive('create')->never();

        $this->expectException(PayRamConfigurationException::class);
        $this->handler()->handle('ORDSHORT');
    }

    public function test_it_allows_an_invoice_at_the_crypto_floor(): void
    {
        config(['services.payram.min_invoice_usd' => 1.00]);

        $order = $this->makeOrder(currency: 'USD', totalGross: 1.00);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findByShortId')->andReturn($order);
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($order);
        $this->sessionService->shouldReceive('verifySession')->andReturn(true);
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->makeSettings([PaymentProviders::PAYRAM->value]));
        $this->payramPaymentsRepository->shouldReceive('findWhere')->andReturn(new Collection);
        $this->payramPaymentsRepository->shouldReceive('create')->andReturn(new PayramPaymentDomainObject);
        $this->payramClient->shouldReceive('createPayment')
            ->once()
            ->andReturn(new PayRamPaymentSessionDTO(
                referenceId: 'ref-floor',
                checkoutUrl: 'https://pay.monno.io/payments?reference_id=ref-floor',
                host: 'https://pay.monno.io',
            ));

        $response = $this->handler()->handle('ORDSHORT');

        $this->assertSame('ref-floor', $response->referenceId);
    }

    public function test_the_floor_does_not_apply_to_a_usd_order_above_it(): void
    {
        // A normal crypto order is unaffected: the residual rounding risk is
        // only material at very small amounts.
        config(['services.payram.min_invoice_usd' => 1.00]);

        $order = $this->makeOrder(currency: 'USD', totalGross: 50.00);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findByShortId')->andReturn($order);
        $this->orderRepository->shouldReceive('updateFromArray')->andReturn($order);
        $this->sessionService->shouldReceive('verifySession')->andReturn(true);
        $this->eventSettingsRepository->shouldReceive('findFirstWhere')
            ->andReturn($this->makeSettings([PaymentProviders::PAYRAM->value]));
        $this->payramPaymentsRepository->shouldReceive('findWhere')->andReturn(new Collection);
        $this->payramPaymentsRepository->shouldReceive('create')->andReturn(new PayramPaymentDomainObject);
        $this->payramClient->shouldReceive('createPayment')
            ->once()
            ->andReturn(new PayRamPaymentSessionDTO(
                referenceId: 'ref-big',
                checkoutUrl: 'https://pay.monno.io/payments?reference_id=ref-big',
                host: 'https://pay.monno.io',
            ));

        $response = $this->handler()->handle('ORDSHORT');

        $this->assertSame('ref-big', $response->referenceId);
    }
}
