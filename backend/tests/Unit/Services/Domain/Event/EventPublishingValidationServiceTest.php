<?php

namespace Tests\Unit\Services\Domain\Event;

use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Exceptions\CannotPublishEventWithoutPaymentMethodException;
use HiEvents\Models\Account;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Event\EventPublishingValidationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EventPublishingValidationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private int $eventId;

    private int $organizerId;

    protected function setUp(): void
    {
        parent::setUp();

        $account = Account::factory()->create();
        $user = User::factory()->create();

        $this->organizerId = DB::table('organizers')->insertGetId([
            'account_id' => $account->id,
            'name' => 'Gate Test Organizer',
            'email' => 'gate@test.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->eventId = DB::table('events')->insertGetId([
            'title' => 'Gate Test Event',
            'short_id' => 'GATE'.random_int(100000, 999999),
            'organizer_id' => $this->organizerId,
            'account_id' => $account->id,
            'user_id' => $user->id,
            'currency' => 'USD',
            'status' => EventStatus::DRAFT->name,
            'start_date' => now()->addDay(),
            'end_date' => now()->addDay()->addHours(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addProduct(float $price): void
    {
        $productId = DB::table('products')->insertGetId([
            'title' => 'Ticket',
            'event_id' => $this->eventId,
            'order' => 1,
            'created_at' => now(),
        ]);

        DB::table('product_prices')->insert([
            'product_id' => $productId,
            'price' => $price,
            'order' => 1,
            'created_at' => now(),
        ]);
    }

    private function setProviders(array $providers): void
    {
        DB::table('event_settings')->insert([
            'event_id' => $this->eventId,
            'payment_providers' => json_encode($providers),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validate(): void
    {
        app(EventPublishingValidationService::class)
            ->validateCanPublish($this->eventId, $this->organizerId);
    }

    public function test_a_free_event_publishes_without_any_payment_method(): void
    {
        $this->addProduct(0);
        config(['app.stripe_enabled' => true]);

        $this->validate();

        $this->assertTrue(true);
    }

    public function test_a_paid_event_whose_only_provider_is_disabled_is_blocked(): void
    {
        // ["STRIPE"] with card payments off resolves to nothing, so the
        // organizer has to choose a method that actually works here.
        $this->addProduct(25);
        $this->setProviders(['STRIPE']);
        config(['app.stripe_enabled' => false]);

        $this->expectException(CannotPublishEventWithoutPaymentMethodException::class);

        $this->validate();
    }

    public function test_a_paid_event_with_crypto_selected_publishes_once_payram_confirms_the_wallet(): void
    {
        $this->addProduct(25);
        $this->setProviders(['PAYRAM']);
        config(['app.stripe_enabled' => false]);

        $this->insertPayRamAccount();
        $this->fakeGatewayStatus(coldWalletConfigured: true);
        Cache::flush();

        $this->validate();

        $this->assertTrue(true);
    }

    public function test_a_paid_event_without_a_payment_method_is_blocked_when_stripe_is_on(): void
    {
        $this->addProduct(25);
        config(['app.stripe_enabled' => true]);

        $this->expectException(CannotPublishEventWithoutPaymentMethodException::class);

        $this->validate();
    }

    public function test_a_paid_event_with_offline_payments_publishes(): void
    {
        $this->addProduct(25);
        $this->setProviders(['OFFLINE']);
        config(['app.stripe_enabled' => true]);

        $this->validate();

        $this->assertTrue(true);
    }

    public function test_a_paid_event_with_crypto_is_blocked_until_the_merchant_account_is_ready(): void
    {
        $this->addProduct(25);
        $this->setProviders(['PAYRAM']);
        config(['app.stripe_enabled' => true]);

        DB::table('organizer_payram_accounts')->insert([
            'organizer_id' => $this->organizerId,
            'status' => 'READY',
            'external_platform_id' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(CannotPublishEventWithoutPaymentMethodException::class);

        $this->validate();
    }

    public function test_a_saved_wallet_address_alone_does_not_unlock_publishing(): void
    {
        // The address we used to store proved nothing — nothing on our side
        // wires it up, so it must not satisfy the gate any more.
        $this->addProduct(25);
        $this->setProviders(['PAYRAM']);
        config(['app.stripe_enabled' => true]);

        $this->insertPayRamAccount();
        $this->fakeGatewayStatus(coldWalletConfigured: false);
        Cache::flush();

        $this->expectException(CannotPublishEventWithoutPaymentMethodException::class);

        $this->validate();
    }

    public function test_crypto_is_blocked_when_payram_cannot_be_reached(): void
    {
        $this->addProduct(25);
        $this->setProviders(['PAYRAM']);
        config(['app.stripe_enabled' => true]);

        $this->insertPayRamAccount();
        $this->fakeGatewayStatus(available: false, coldWalletConfigured: false);
        Cache::flush();

        $this->expectException(CannotPublishEventWithoutPaymentMethodException::class);

        $this->validate();
    }

    private function insertPayRamAccount(): void
    {
        DB::table('organizer_payram_accounts')->insert([
            'organizer_id' => $this->organizerId,
            'status' => 'READY',
            'external_platform_id' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The gateway status service is readonly (unmockable), so answer it at the
     * HTTP layer instead — which also exercises the real operator client.
     */
    private function fakeGatewayStatus(bool $available = true, bool $coldWalletConfigured = false): void
    {
        config([
            'services.payram.base_url' => 'https://pay.test',
            'services.payram.operator_email' => 'admin@monno.io',
            'services.payram.operator_password' => 'secret',
        ]);

        if (! $available) {
            Http::fake(['*' => Http::response(['error' => ['code' => 'DOWN']], 500)]);

            return;
        }

        // The gate reads the gateway the same way the settings card does:
        // /project/{id}/wallets, where a sweep destination on a walletScw is what
        // "configured" means. Faking the old balance endpoint here is what let
        // the wrong source survive.
        Http::fake([
            '*signin*' => Http::response(['accessToken' => 'operator-token']),
            '*project/*/wallets*' => Http::response([[
                'id' => 6,
                'name' => 'EVM Deposit Wallet 1',
                'family' => 'ETH_Family',
                'walletType' => 'deposit_wallet',
                'status' => 'active',
                'walletScws' => [[
                    'blockchainCode' => 'ETH',
                    'family' => 'ETH_Family',
                    'fundCollectorAddress' => $coldWalletConfigured ? '0x142e57a939abefb8d50ab39a8ab58ef9572620ef' : '',
                ]],
                'externalPlatformWallets' => [['externalPlatformID' => 99]],
            ]]),
        ]);
    }
}
