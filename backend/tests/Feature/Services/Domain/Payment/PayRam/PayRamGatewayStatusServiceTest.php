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
 * The source is /project/{id}/wallets — the same thing the console's own
 * deposit-wallet page lists. Its shape is not obvious and both details matter:
 * the endpoint returns *shared* wallets, so a row only belongs to this project
 * when the project appears in `externalPlatformWallets`, and "payout wallet
 * configured" is the presence of a `fundCollectorAddress` on a `walletScws`
 * entry. An earlier version read /addresses/balance — a sweep balance, empty
 * until money has moved — so a freshly configured project looked unconfigured.
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
     * A deposit wallet, as the gateway really returns it.
     *
     * @param  array<int, string|null>  $collectorsByNetwork  blockchainCode => collector
     * @param  array<int, int>  $assignedProjects
     */
    private function depositWallet(array $collectorsByNetwork, array $assignedProjects, string $type = 'deposit_wallet'): array
    {
        $scws = [];
        foreach ($collectorsByNetwork as $code => $collector) {
            $scws[] = [
                'blockchainCode' => $code,
                'family' => 'ETH_Family',
                'fundCollectorAddress' => $collector,
            ];
        }

        return [
            'id' => 6,
            'name' => 'EVM Deposit Wallet 1',
            'family' => 'ETH_Family',
            'walletType' => $type,
            'status' => 'active',
            'walletScws' => $scws,
            'externalPlatformWallets' => array_map(
                static fn (int $id): array => ['externalPlatformID' => $id],
                $assignedProjects,
            ),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $wallets
     */
    private function fakeWallets(array $wallets): void
    {
        Http::fake([
            '*signin*' => Http::response(['accessToken' => 'operator-token']),
            '*project/*/wallets*' => Http::response($wallets),
        ]);
    }

    private function gatewayStatus(int $projectId = 9): array
    {
        return app(PayRamGatewayStatusService::class)->forProject($projectId);
    }

    public function test_a_configured_network_means_the_organizer_is_live(): void
    {
        $this->fakeWallets([
            $this->depositWallet(['ETH' => '0x142e57a939abefb8d50ab39a8ab58ef9572620ef'], [9]),
        ]);

        $status = $this->gatewayStatus();

        $this->assertTrue($status['available']);
        $this->assertTrue($status['cold_wallet_configured']);
        $this->assertSame(1, count($status['configured_networks']));
        $this->assertSame('0x142e57a939abefb8d50ab39a8ab58ef9572620ef', $status['networks'][0]['payout_address']);
    }

    public function test_a_wallet_with_no_sweep_destination_is_not_configured(): void
    {
        // PayRam refuses to deploy a contract without a cold wallet, so a
        // missing fundCollectorAddress is exactly "not set up".
        $this->fakeWallets([
            $this->depositWallet(['ETH' => null], [9]),
        ]);

        $status = $this->gatewayStatus();

        $this->assertFalse($status['cold_wallet_configured']);
        $this->assertSame([], $status['configured_networks']);
    }

    public function test_one_configured_network_is_enough_even_when_others_are_not(): void
    {
        // Ethereum done, Base and Polygon not: PayRam accepts payments on
        // Ethereum today and Monno must not claim otherwise.
        $this->fakeWallets([
            $this->depositWallet(['ETH' => '0xethcollector', 'BASE' => null, 'POLYGON' => null], [9]),
        ]);

        $status = $this->gatewayStatus();

        $this->assertTrue($status['cold_wallet_configured'], 'One working network means crypto is live.');
        $this->assertSame(1, count($status['configured_networks']));
        $this->assertSame(3, count($status['networks']), 'Unconfigured networks are still reported.');
        $this->assertFalse($status['default_cold_wallet_set'], 'Not every network is done, and that is stated.');
    }

    public function test_wallets_shared_from_another_project_are_not_counted(): void
    {
        // The endpoint returns shared wallets. A wallet assigned only to
        // project 1 must not make project 9 look configured.
        $this->fakeWallets([
            $this->depositWallet(['ETH' => '0xsomething'], [1, 2]),
        ]);

        $status = $this->gatewayStatus(9);

        $this->assertFalse($status['cold_wallet_configured']);
        $this->assertSame(0, count($status['networks']));
    }

    public function test_hot_wallets_are_not_mistaken_for_the_organizers_setup(): void
    {
        $this->fakeWallets([
            $this->depositWallet(['ETH' => '0xhot'], [9], type: 'hot_wallet'),
        ]);

        $status = $this->gatewayStatus();

        $this->assertFalse($status['cold_wallet_configured'], 'Hot wallets are the operator machinery, not the organizer setup.');
    }

    public function test_no_wallets_at_all_is_not_ready(): void
    {
        $this->fakeWallets([]);

        $status = $this->gatewayStatus();

        $this->assertTrue($status['available']);
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
