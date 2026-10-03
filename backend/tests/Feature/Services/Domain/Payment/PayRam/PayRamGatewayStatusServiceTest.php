<?php

namespace Tests\Feature\Services\Domain\Payment\PayRam;

use HiEvents\Services\Domain\Payment\PayRam\PayRamGatewayStatusService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The gateway's own view of a project, which is what Monno reports as "ready".
 *
 * The rule under test: each deposit wallet is a separate contract with its own
 * sweep destination, and the organizer chooses which networks to accept — so ONE
 * configured network means they can take money today. This used to be an AND
 * across every wallet, which meant adding a second network switched the first
 * one off.
 */
class PayRamGatewayStatusServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payram.base_url' => 'https://pay.test',
            'services.payram.operator_email' => 'admin@monno.io',
            'services.payram.operator_password' => 'secret',
        ]);

        Cache::flush();
    }

    /**
     * @param  array<int, array<string, mixed>>  $wallets
     */
    private function fakeWallets(array $wallets): void
    {
        Http::fake([
            '*signin*' => Http::response(['accessToken' => 'operator-token']),
            '*addresses/balance*' => Http::response($wallets),
        ]);
    }

    private function gatewayStatus(): array
    {
        return app(PayRamGatewayStatusService::class)->forProject(9);
    }

    public function test_a_configured_network_means_the_organizer_is_live(): void
    {
        $this->fakeWallets([
            ['walletName' => 'EVM Deposit Wallet 1', 'blockchainCode' => 'ETH', 'coldWalletConfigured' => true],
        ]);

        $status = $this->gatewayStatus();

        $this->assertTrue($status['available']);
        $this->assertTrue($status['cold_wallet_configured']);
        $this->assertSame(1, count($status['configured_networks']));
    }

    public function test_one_configured_network_is_enough_even_when_others_are_not(): void
    {
        // The exact shape the organizer reported: Ethereum done, Base and
        // Polygon not. PayRam accepts payments on Ethereum today, and Monno
        // must not claim otherwise.
        $this->fakeWallets([
            ['walletName' => 'EVM Deposit Wallet 1', 'blockchainCode' => 'ETH', 'coldWalletConfigured' => true, 'defaultColdWalletSet' => true],
            ['walletName' => 'EVM Deposit Wallet 2', 'blockchainCode' => 'BASE', 'coldWalletConfigured' => false],
            ['walletName' => 'EVM Deposit Wallet 3', 'blockchainCode' => 'POLYGON', 'coldWalletConfigured' => false],
        ]);

        $status = $this->gatewayStatus();

        $this->assertTrue($status['cold_wallet_configured'], 'One working network means crypto is live.');
        $this->assertSame(1, count($status['configured_networks']));
        $this->assertSame(3, count($status['networks']), 'As-yet-unconfigured networks are still reported.');
        $this->assertFalse($status['default_cold_wallet_set'], 'Not every network is done, and that is stated.');
    }

    public function test_nothing_configured_is_not_ready(): void
    {
        $this->fakeWallets([
            ['walletName' => 'EVM Deposit Wallet 1', 'blockchainCode' => 'ETH', 'coldWalletConfigured' => false],
        ]);

        $status = $this->gatewayStatus();

        $this->assertFalse($status['cold_wallet_configured']);
        $this->assertSame([], $status['configured_networks']);
    }

    public function test_no_wallets_at_all_is_not_ready(): void
    {
        $this->fakeWallets([]);

        $status = $this->gatewayStatus();

        $this->assertFalse($status['cold_wallet_configured']);
        $this->assertFalse($status['default_cold_wallet_set']);
    }

    public function test_an_unreachable_gateway_says_so_rather_than_guessing(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'DOWN']], 500)]);

        $status = $this->gatewayStatus();

        $this->assertFalse($status['available'], 'We must never report a guess as a fact.');
        $this->assertFalse($status['cold_wallet_configured']);
    }

    public function test_a_project_without_an_id_is_unavailable(): void
    {
        $status = app(PayRamGatewayStatusService::class)->forProject(null);

        $this->assertFalse($status['available']);
    }
}
