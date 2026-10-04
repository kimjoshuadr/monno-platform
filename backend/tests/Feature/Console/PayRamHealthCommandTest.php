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
}
