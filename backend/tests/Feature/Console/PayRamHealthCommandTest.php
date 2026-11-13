<?php

namespace Tests\Feature\Console;

use HiEvents\DomainObjects\OrganizerPayramAccountDomainObject;
use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class PayRamHealthCommandTest extends TestCase
{
    private function account(int $projectId, string $name): OrganizerPayramAccountDomainObject
    {
        $account = new OrganizerPayramAccountDomainObject;
        $account->setOrganizerId(1)->setExternalPlatformId($projectId)->setProjectName($name);

        return $account;
    }

    private function hotWallet(int $id, int $projectId): array
    {
        return [
            'id' => $id,
            'walletType' => 'hot_wallet',
            'externalPlatformWallets' => [['externalPlatformID' => $projectId]],
        ];
    }

    private function depositWallet(int $id, int $projectId, ?string $collector = '0xcollector'): array
    {
        return [
            'id' => $id,
            'walletType' => 'deposit_wallet',
            'walletScws' => [['blockchainCode' => 'ETH', 'fundCollectorAddress' => $collector]],
            'externalPlatformWallets' => [['externalPlatformID' => $projectId]],
        ];
    }

    private function bind(array $accounts, PayRamOperatorClient $client): void
    {
        // Most tests do not care about fee drift; give it an empty sweep list
        // unless the test asks for one.
        $client->shouldReceive('getProjectSweeps')->andReturn([])->byDefault();

        $this->app->instance(PayRamOperatorClient::class, $client);

        $repository = Mockery::mock(OrganizerPayRamAccountsRepositoryInterface::class);
        $repository->shouldReceive('all')->andReturn(collect($accounts));
        $this->app->instance(OrganizerPayRamAccountsRepositoryInterface::class, $repository);
    }

    public function test_a_configured_project_with_a_hot_wallet_and_no_errors_is_healthy(): void
    {
        config(['services.payram.hot_wallet_id' => 5]);

        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldReceive('getProjectWallets')->with(9)->andReturn([
            $this->depositWallet(6, 9),
            $this->hotWallet(5, 9),
        ]);
        $client->shouldReceive('getProjectAddressBalances')->with(9)->andReturn([
            ['walletName' => 'EVM Deposit', 'lastSweepError' => null],
        ]);
        $this->bind([$this->account(9, 'GN Club')], $client);

        $exit = Artisan::call('monno:payram-health');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('all projects healthy', Artisan::output());
    }

    public function test_a_configured_project_without_a_hot_wallet_is_flagged(): void
    {
        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldReceive('getProjectWallets')->with(9)->andReturn([
            $this->depositWallet(6, 9), // can take money...
            // ...but no hot wallet, so it cannot sweep.
        ]);
        $client->shouldReceive('getProjectAddressBalances')->with(9)->andReturn([]);
        $this->bind([$this->account(9, 'GN Club')], $client);

        $exit = Artisan::call('monno:payram-health');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('missing_hot_wallet', Artisan::output());
    }

    public function test_a_project_with_no_deposit_wallet_is_not_flagged(): void
    {
        // Nothing to sweep yet — the organizer has not finished setup.
        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldReceive('getProjectWallets')->with(35)->andReturn([]);
        $client->shouldReceive('getProjectAddressBalances')->with(35)->andReturn([]);
        $this->bind([$this->account(35, 'GN Club 26')], $client);

        $exit = Artisan::call('monno:payram-health');

        $this->assertSame(0, $exit);
    }

    public function test_a_failed_sweep_is_flagged(): void
    {
        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldReceive('getProjectWallets')->with(9)->andReturn([
            $this->depositWallet(6, 9),
            $this->hotWallet(5, 9),
        ]);
        $client->shouldReceive('getProjectAddressBalances')->with(9)->andReturn([
            ['walletName' => 'EVM Deposit', 'lastSweepError' => ['statusCode' => 'DEPOSIT_NOT_DEPLOYED_LOW_GAS', 'reason' => 'insufficient fees']],
        ]);
        $this->bind([$this->account(9, 'GN Club')], $client);

        $exit = Artisan::call('monno:payram-health');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('sweep_failed', Artisan::output());
    }

    public function test_an_inactive_hot_wallet_is_flagged(): void
    {
        // A hot wallet that is attached but inactive pays no gas, so funds sit
        // still while the console makes everything look set up.
        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldReceive('getProjectWallets')->with(9)->andReturn([
            $this->depositWallet(6, 9),
            $this->hotWallet(5, 9),
        ]);
        $client->shouldReceive('getProjectAddressBalances')->with(9)->andReturn([
            [
                'walletName' => 'EVM Deposit Wallet 1',
                'blockchainCode' => 'ETH',
                'coldWalletConfigured' => true,
                'hotWalletActive' => false,
                'lastSweepError' => null,
            ],
        ]);
        $this->bind([$this->account(9, 'GN Club')], $client);

        $exit = Artisan::call('monno:payram-health');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('inactive_hot_wallet', Artisan::output());
    }

    public function test_fee_drift_between_the_quote_and_the_last_sweep_is_flagged(): void
    {
        // PayRam can move its settlement rate. If the last sweep charged 4% but
        // we still quote 2.5%, the buyer's markup no longer covers the fee and
        // the organizer silently eats the difference. That is worth an alarm.
        config(['services.payram.settlement_fee_bps' => 250]);

        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldReceive('getProjectWallets')->with(9)->andReturn([
            $this->depositWallet(6, 9),
            $this->hotWallet(5, 9),
        ]);
        $client->shouldReceive('getProjectAddressBalances')->with(9)->andReturn([]);
        $client->shouldReceive('getProjectSweeps')->with(9, 40)->andReturn([
            ['status' => 'fund_transfer', 'amount' => '0.000096', 'txHash' => '0xabc', 'toAddress' => '0xcold'],
            ['status' => 'fee_transfer_processed', 'amount' => '0.000004', 'txHash' => '0xabc'],
            ['status' => 'fund_collect_processed', 'amount' => '0.0001', 'txHash' => '0xabc'],
        ]);
        $this->bind([$this->account(9, 'GN Club')], $client);

        $exit = Artisan::call('monno:payram-health');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('settlement_fee_drift', Artisan::output());
    }

    public function test_a_settlement_at_the_quoted_rate_is_not_flagged(): void
    {
        config(['services.payram.settlement_fee_bps' => 250]);

        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldReceive('getProjectWallets')->with(9)->andReturn([
            $this->depositWallet(6, 9),
            $this->hotWallet(5, 9),
        ]);
        $client->shouldReceive('getProjectAddressBalances')->with(9)->andReturn([]);
        $client->shouldReceive('getProjectSweeps')->with(9, 40)->andReturn([
            ['status' => 'fund_transfer', 'amount' => '0.0000975', 'txHash' => '0xabc', 'toAddress' => '0xcold'],
            ['status' => 'fee_transfer_processed', 'amount' => '0.0000025', 'txHash' => '0xabc'],
            ['status' => 'fund_collect_processed', 'amount' => '0.0001', 'txHash' => '0xabc'],
        ]);
        $this->bind([$this->account(9, 'GN Club')], $client);

        $exit = Artisan::call('monno:payram-health');

        $this->assertSame(0, $exit);
    }
}
