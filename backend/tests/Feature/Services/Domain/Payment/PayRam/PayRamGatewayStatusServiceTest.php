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
     * @param  array<int, array<string, mixed>>  $balances
     * @param  array<int, array<string, mixed>>  $sweeps
     */
    private function fakeWallets(array $wallets, array $balances = [], array $sweeps = [], array $projectFees = [], array $feeDefaults = []): void
    {
        Http::fake([
            '*signin*' => Http::response(['accessToken' => 'operator-token']),
            '*project/*/wallets*' => Http::response($wallets),
            '*project/*/addresses/balance*' => Http::response($balances),
            '*project/*/sweeps*' => Http::response(['data' => $sweeps, 'total' => count($sweeps)]),
            '*operator/fees/projects*' => Http::response(['projectFees' => $projectFees]),
            '*operator/fees/defaults*' => Http::response(['defaults' => $feeDefaults]),
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

    public function test_a_failure_is_never_cached(): void
    {
        // A transient failure must not be remembered: caching "unreachable" for
        // 20s tells the organizer their gateway is down when it is not, and
        // fails the publish gate on the strength of one bad moment.
        // Http::fake() appends, so one stateful fake plays both moments:
        // unreachable first, then recovered.
        $down = true;
        Http::fake(function ($request) use (&$down) {
            if ($down) {
                return Http::response(['error' => ['code' => 'DOWN']], 500);
            }

            if (str_ends_with($request->url(), '/api/v1/signin')) {
                return Http::response(['accessToken' => 'operator-token']);
            }

            if (str_contains($request->url(), '/wallets')) {
                return Http::response([
                    $this->depositWallet(['ETH' => '0xcollector'], [9]),
                ]);
            }

            return Http::response(['status' => 'ok']);
        });

        $first = $this->gatewayStatus();
        $this->assertFalse($first['available']);

        // The gateway recovers; the very next call must see that.
        $down = false;
        $second = $this->gatewayStatus();

        $this->assertTrue($second['available'], 'A recovered gateway must not be masked by a cached failure.');
        $this->assertTrue($second['cold_wallet_configured']);
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

    public function test_it_reports_what_is_awaiting_sweep(): void
    {
        $this->fakeWallets(
            [$this->depositWallet(['ETH' => '0xcollector'], [9])],
            [[
                'walletName' => 'EVM Deposit Wallet 1',
                'blockchainCode' => 'ETH',
                'currencyCode' => 'ETH',
                'amount' => '0.5',
                'amountUSD' => '1500',
                'eligibleForSweepCount' => 2,
                'eligibleForSweepAmount' => '0.12',
                'eligibleForSweepAmountUSD' => '360',
            ]],
        );

        $status = $this->gatewayStatus();

        $this->assertCount(1, $status['eligible_for_sweep']);
        $this->assertSame('0.12', $status['eligible_for_sweep'][0]['amount']);
        $this->assertSame('ETH', $status['eligible_for_sweep'][0]['currency_code']);
        $this->assertSame('360', $status['eligible_for_sweep'][0]['amount_usd']);
        $this->assertNull($status['last_sweep_error']);
    }

    public function test_it_reports_funds_that_cannot_sweep_and_why(): void
    {
        // The real incident: money in the deposit wallet, nothing eligible, and
        // the sweep failing because no hot wallet is assigned to fund it. The
        // card used to hardcode this away and claim sweeps were fine.
        $this->fakeWallets(
            [$this->depositWallet(['ETH' => '0xcollector'], [9])],
            [[
                'walletName' => 'EVM Deposit Wallet 1',
                'blockchainCode' => 'ETH',
                'currencyCode' => 'ETH',
                'amount' => '0.00138',
                'amountUSD' => '3.63',
                'eligibleForSweepCount' => 0,
                'eligibleForSweepAmount' => '0',
                'hotWalletActive' => false,
                'action' => 'sweep_not_allowed',
                'lastSweepError' => [
                    'statusCode' => 'HOT_WALLET_MISSING',
                    'category' => 'recoverable',
                    'reason' => 'No hot wallet assigned for ETH_Family. Deployment cannot be funded.',
                    'actionHint' => 'Assign a hot wallet for ETH_Family to enable deployments and sweeps.',
                ],
            ]],
        );

        $status = $this->gatewayStatus();

        $this->assertSame([], $status['eligible_for_sweep'], 'Nothing is eligible to sweep.');
        $this->assertNotNull($status['last_sweep_error']);
        $this->assertSame('HOT_WALLET_MISSING', $status['last_sweep_error']['statusCode']);
        $this->assertStringContainsString('hot wallet', $status['last_sweep_error']['reason']);
    }

    public function test_a_stale_sweep_error_with_nothing_to_sweep_is_not_reported(): void
    {
        // PayRam keeps the last sweep error even after the problem is fixed, so
        // an organizer would chase a resolved warning forever. With no balance
        // waiting to move it is history, not a problem.
        $this->fakeWallets(
            [$this->depositWallet(['ETH' => '0xcollector'], [9])],
            [[
                'walletName' => 'EVM Deposit Wallet 1',
                'blockchainCode' => 'ETH',
                'currencyCode' => 'ETH',
                'amount' => '0',
                'amountUSD' => '0',
                'eligibleForSweepCount' => 0,
                'eligibleForSweepAmount' => '0',
                'action' => 'no_balance',
                'lastSweepError' => [
                    'statusCode' => 'DEPOSIT_NOT_DEPLOYED_LOW_GAS',
                    'reason' => 'Deposit wallets on ETH cannot be deployed yet: insufficient fees',
                ],
            ]],
        );

        $status = $this->gatewayStatus();

        $this->assertNull($status['last_sweep_error'], 'A resolved error with no balance waiting is history.');
    }

    public function test_a_failing_balance_read_does_not_break_readiness(): void
    {
        // Readiness comes from the wallets call; only the sweep detail is lost
        // if the balance call fails, and it must not take the whole report down.
        Http::fake([
            '*signin*' => Http::response(['accessToken' => 'operator-token']),
            '*project/*/wallets*' => Http::response([
                $this->depositWallet(['ETH' => '0xcollector'], [9]),
            ]),
            '*project/*/addresses/balance*' => Http::response(['error' => 'boom'], 500),
            '*project/*/sweeps*' => Http::response(['data' => [], 'total' => 0]),
        ]);

        $status = $this->gatewayStatus();

        $this->assertTrue($status['available']);
        $this->assertTrue($status['cold_wallet_configured']);
        $this->assertSame([], $status['eligible_for_sweep']);
        $this->assertNull($status['last_sweep_error']);
    }

    public function test_it_reports_recent_settlements_to_the_cold_wallet(): void
    {
        $this->fakeWallets(
            [$this->depositWallet(['ETH' => '0xcollector'], [9])],
            [],
            [
                // per-sweep legs: the fund transfer is what reaches the organizer
                ['status' => 'fund_collect_processed', 'amount' => '0.00063', 'currencyCode' => 'ETH'],
                ['status' => 'fund_transfer', 'amount' => '0.001254', 'currencyCode' => 'ETH', 'toAddress' => '0xcold', 'txHash' => '0xabc', 'timestamp' => '2026-10-03T14:27:38Z'],
            ],
        );

        $status = $this->gatewayStatus();

        $this->assertCount(1, $status['recent_settlements']);
        $this->assertSame('0.001254', $status['recent_settlements'][0]['net']);
        $this->assertSame('ETH', $status['recent_settlements'][0]['currency_code']);
        $this->assertSame('0xcold', $status['recent_settlements'][0]['destination']);
        $this->assertSame('0xabc', $status['recent_settlements'][0]['transaction_hash']);
    }

    public function test_it_breaks_a_settlement_into_its_fee_legs(): void
    {
        // A real sweep transaction carries four legs that share one tx hash:
        // the collect from the deposit address, PayRam's fee, Monno's operator
        // fee, and the net that lands in the cold wallet. The card shows the
        // breakdown so the organizer can check what left against what arrived.
        $this->fakeWallets(
            [$this->depositWallet(['ETH' => '0xcollector'], [9])],
            [],
            [
                ['status' => 'operator_fee_transfer_processed', 'amount' => '0.0000015', 'currencyCode' => 'ETH', 'toAddress' => '0xaE30', 'txHash' => '0xabc', 'timestamp' => '2026-10-04T01:37:09Z'],
                ['status' => 'fee_transfer_processed', 'amount' => '0.0000015', 'currencyCode' => 'ETH', 'toAddress' => '0xEEDb', 'txHash' => '0xabc', 'timestamp' => '2026-10-04T01:37:04Z'],
                ['status' => 'fund_transfer', 'amount' => '0.000057', 'currencyCode' => 'ETH', 'toAddress' => '0xcold', 'txHash' => '0xabc', 'timestamp' => '2026-10-04T01:36:59Z'],
                ['status' => 'fund_collect_processed', 'amount' => '0.00006', 'currencyCode' => 'ETH', 'toAddress' => '0xsweeper', 'txHash' => '0xabc', 'timestamp' => '2026-10-04T01:36:54Z'],
            ],
        );

        $status = $this->gatewayStatus();

        $settlement = $status['recent_settlements'][0];
        $this->assertSame('0.00006', $settlement['gross']);
        $this->assertSame('0.0000015', $settlement['payram_fee']);
        $this->assertSame('0.0000015', $settlement['operator_fee']);
        $this->assertSame('0.000057', $settlement['net']);
        $this->assertSame('0xcold', $settlement['destination']);
        $this->assertSame('0xabc', $settlement['transaction_hash']);
        // 0.0000015 / 0.00006 = 2.5%.
        $this->assertSame(250, $settlement['realised_rate_bps']);
    }

    public function test_it_sums_a_batched_sweep_rather_than_keeping_one_leg(): void
    {
        // A sweep can consolidate several deposit addresses into one
        // transaction, so more than one collect leg shares the hash. Keeping
        // only the last made the gross smaller than the net and the rate
        // nonsense (55%), which is exactly what the live card first showed.
        $this->fakeWallets(
            [$this->depositWallet(['ETH' => '0xcollector'], [9])],
            [],
            [
                ['status' => 'operator_fee_transfer_processed', 'amount' => '0.000033', 'currencyCode' => 'ETH', 'txHash' => '0xmulti', 'timestamp' => '2026-10-03T14:27:53Z'],
                ['status' => 'fee_transfer_processed', 'amount' => '0.000033', 'currencyCode' => 'ETH', 'txHash' => '0xmulti', 'timestamp' => '2026-10-03T14:27:53Z'],
                ['status' => 'fund_transfer', 'amount' => '0.001254', 'currencyCode' => 'ETH', 'toAddress' => '0xcold', 'txHash' => '0xmulti', 'timestamp' => '2026-10-03T14:27:53Z'],
                ['status' => 'fund_collect_processed', 'amount' => '0.00006', 'currencyCode' => 'ETH', 'txHash' => '0xmulti', 'timestamp' => '2026-10-03T14:27:53Z'],
                ['status' => 'fund_collect_processed', 'amount' => '0.00126', 'currencyCode' => 'ETH', 'txHash' => '0xmulti', 'timestamp' => '2026-10-03T14:27:53Z'],
            ],
        );

        $status = $this->gatewayStatus();

        $settlement = $status['recent_settlements'][0];
        $this->assertSame('0.00132', $settlement['gross'], 'Batched collects must be summed.');
        $this->assertSame('0.001254', $settlement['net']);
        // 0.000033 / 0.00132 = 2.5%.
        $this->assertSame(250, $settlement['realised_rate_bps']);
    }

    public function test_it_resolves_the_merchants_operator_fee_per_chain(): void
    {
        // The fee is not ours to configure: a project override wins over the
        // chain default, and the card shows whichever is in force.
        $this->fakeWallets(
            [$this->depositWallet(['ETH' => '0xcollector'], [9])],
            [],
            [],
            [
                ['projectID' => 9, 'feeBps' => 400, 'blockchain' => ['code' => 'ETH']],
                ['projectID' => 77, 'feeBps' => 999, 'blockchain' => ['code' => 'ETH']],
            ],
            [
                ['feeBps' => 250, 'blockchain' => ['code' => 'ETH']],
                ['feeBps' => 300, 'blockchain' => ['code' => 'BTC']],
            ],
        );

        $status = $this->gatewayStatus();

        $this->assertSame(400, $status['fees']['ETH']['bps'], 'The project override wins.');
        $this->assertSame('project', $status['fees']['ETH']['source']);
        $this->assertSame(300, $status['fees']['BTC']['bps'], 'No override — the chain default applies.');
        $this->assertSame('default', $status['fees']['BTC']['source']);
    }

    public function test_a_project_with_only_a_deposit_wallet_has_no_hot_wallet(): void
    {
        // Payments land, but nothing pays the gas to sweep them — the funds are
        // stuck, and the card has to be able to say so.
        $this->fakeWallets([$this->depositWallet(['ETH' => '0xcollector'], [9])]);

        $this->assertFalse($this->gatewayStatus()['hot_wallet_configured']);
    }

    public function test_a_project_with_a_hot_wallet_reports_it(): void
    {
        $this->fakeWallets([
            $this->depositWallet(['ETH' => '0xcollector'], [9]),
            $this->depositWallet([], [9], 'hot_wallet'),
        ]);

        $this->assertTrue($this->gatewayStatus()['hot_wallet_configured']);
    }

    public function test_a_cache_failure_does_not_break_the_status(): void
    {
        // A cache store that cannot be read or written (bad permissions) must
        // never turn the money-path status into a 500.
        $this->fakeWallets([
            $this->depositWallet(['ETH' => '0xcollector'], [9]),
        ]);

        Cache::shouldReceive('get')->andReturnUsing(function (string $key) {
            if (str_contains($key, 'gateway_status')) {
                throw new \RuntimeException('cache down');
            }

            return null;
        });
        Cache::shouldReceive('put')->andReturnUsing(function (string $key) {
            if (str_contains($key, 'gateway_status')) {
                throw new \RuntimeException('cache down');
            }

            return true;
        });
        Cache::shouldReceive('forget')->andReturnTrue();

        $status = $this->gatewayStatus();

        $this->assertTrue($status['available']);
        $this->assertTrue($status['cold_wallet_configured']);
    }
}
