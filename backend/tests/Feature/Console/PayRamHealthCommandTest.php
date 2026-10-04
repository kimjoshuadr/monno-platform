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

    private function bind(array $accounts, PayRamOperatorClient $client): void
    {
        $this->app->instance(PayRamOperatorClient::class, $client);

        $repository = Mockery::mock(OrganizerPayRamAccountsRepositoryInterface::class);
        $repository->shouldReceive('all')->andReturn(collect($accounts));
        $this->app->instance(OrganizerPayRamAccountsRepositoryInterface::class, $repository);
    }

    public function test_a_project_with_the_shared_hot_wallet_and_no_errors_is_healthy(): void
    {
        config(['services.payram.hot_wallet_id' => 5]);

        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldReceive('getProjectWallets')->with(9)->andReturn([$this->hotWallet(5, 9)]);
        $client->shouldReceive('getProjectAddressBalances')->with(9)->andReturn([
            ['walletName' => 'EVM Deposit', 'lastSweepError' => null],
        ]);
        $this->bind([$this->account(9, 'GN Club')], $client);

        $exit = Artisan::call('monno:payram-health');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('all projects healthy', Artisan::output());
    }

    public function test_a_project_with_the_wrong_hot_wallet_is_flagged(): void
    {
        config(['services.payram.hot_wallet_id' => 5]);

        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldReceive('getProjectWallets')->with(9)->andReturn([$this->hotWallet(99, 9)]);
        $client->shouldReceive('getProjectAddressBalances')->with(9)->andReturn([]);
        $this->bind([$this->account(9, 'GN Club')], $client);

        $exit = Artisan::call('monno:payram-health');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('hot_wallet_deviation', Artisan::output());
    }

    public function test_a_failed_sweep_is_flagged(): void
    {
        config(['services.payram.hot_wallet_id' => 5]);

        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldReceive('getProjectWallets')->with(9)->andReturn([$this->hotWallet(5, 9)]);
        $client->shouldReceive('getProjectAddressBalances')->with(9)->andReturn([
            ['walletName' => 'EVM Deposit', 'lastSweepError' => ['statusCode' => 'HOT_WALLET_MISSING', 'reason' => 'No hot wallet assigned']],
        ]);
        $this->bind([$this->account(9, 'GN Club')], $client);

        $exit = Artisan::call('monno:payram-health');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('sweep_failed', Artisan::output());
    }
}
