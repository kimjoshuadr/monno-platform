<?php

namespace Tests\Feature\Console;

use HiEvents\DomainObjects\OrganizerPayramAccountDomainObject;
use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

/**
 * Repairs projects that are missing the shared hot wallet — without it their
 * buyer funds can never sweep.
 */
class PayRamAssignHotWalletTest extends TestCase
{
    private function account(?int $projectId, string $name): OrganizerPayramAccountDomainObject
    {
        $account = new OrganizerPayramAccountDomainObject;
        $account->setExternalPlatformId($projectId);
        $account->setProjectName($name);

        return $account;
    }

    public function test_it_assigns_the_hot_wallet_to_every_project(): void
    {
        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldReceive('assignHotWallet')->once()->with(42, 5);
        $client->shouldReceive('assignHotWallet')->once()->with(43, 5);
        $this->app->instance(PayRamOperatorClient::class, $client);

        $repository = Mockery::mock(OrganizerPayRamAccountsRepositoryInterface::class);
        $repository->shouldReceive('all')->andReturn(collect([
            $this->account(42, 'Acme Run'),
            $this->account(43, 'Beta Events'),
            $this->account(null, 'No project yet'),
        ]));
        $this->app->instance(OrganizerPayRamAccountsRepositoryInterface::class, $repository);

        $exit = Artisan::call('monno:payram-assign-hot-wallet', ['--wallet' => 5]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('2 projects checked', Artisan::output());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldNotReceive('assignHotWallet');
        $this->app->instance(PayRamOperatorClient::class, $client);

        $repository = Mockery::mock(OrganizerPayRamAccountsRepositoryInterface::class);
        $repository->shouldReceive('all')->andReturn(collect([$this->account(42, 'Acme Run')]));
        $this->app->instance(OrganizerPayRamAccountsRepositoryInterface::class, $repository);

        $exit = Artisan::call('monno:payram-assign-hot-wallet', ['--wallet' => 5, '--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('would assign', Artisan::output());
    }

    public function test_it_fails_without_a_wallet_id(): void
    {
        config(['services.payram.hot_wallet_id' => 0]);

        $repository = Mockery::mock(OrganizerPayRamAccountsRepositoryInterface::class);
        $repository->shouldNotReceive('all');
        $this->app->instance(OrganizerPayRamAccountsRepositoryInterface::class, $repository);

        $exit = Artisan::call('monno:payram-assign-hot-wallet');

        $this->assertSame(1, $exit);
    }
}
