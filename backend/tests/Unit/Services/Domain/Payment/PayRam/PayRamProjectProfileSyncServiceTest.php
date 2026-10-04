<?php

namespace Tests\Unit\Services\Domain\Payment\PayRam;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerPayramAccountDomainObject;
use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Payment\PayRam\PayRamProjectProfileSyncService;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Mockery;
use Tests\TestCase;

class PayRamProjectProfileSyncServiceTest extends TestCase
{
    private function organizer(string $name, string $email, ?string $website): OrganizerDomainObject
    {
        $organizer = new OrganizerDomainObject;
        $organizer->setId(29)->setName($name)->setEmail($email)->setWebsite($website);

        return $organizer;
    }

    private function account(int $projectId): OrganizerPayramAccountDomainObject
    {
        $account = new OrganizerPayramAccountDomainObject;
        $account->setOrganizerId(29)->setExternalPlatformId($projectId);

        return $account;
    }

    public function test_it_pushes_the_profile_to_the_payram_project(): void
    {
        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldReceive('updateProjectProfile')
            ->once()
            ->with(9, 'Acme Run (29)', 'https://acme.test', 'hello@acme.test');
        $this->app->instance(PayRamOperatorClient::class, $client);

        $accounts = Mockery::mock(OrganizerPayRamAccountsRepositoryInterface::class);
        $accounts->shouldReceive('findFirstWhere')->andReturn($this->account(9));
        $this->app->instance(OrganizerPayRamAccountsRepositoryInterface::class, $accounts);

        $organizers = Mockery::mock(OrganizerRepositoryInterface::class);
        $organizers->shouldReceive('findById')->with(29)->andReturn($this->organizer('Acme Run', 'hello@acme.test', 'https://acme.test'));
        $this->app->instance(OrganizerRepositoryInterface::class, $organizers);

        $this->service()->syncForOrganizer(29);

        $this->addToAssertionCount(1); // the Mockery expectation is the assertion
    }

    public function test_it_does_nothing_without_a_payram_account(): void
    {
        $client = Mockery::mock(PayRamOperatorClient::class);
        $client->shouldNotReceive('updateProjectProfile');
        $this->app->instance(PayRamOperatorClient::class, $client);

        $accounts = Mockery::mock(OrganizerPayRamAccountsRepositoryInterface::class);
        $accounts->shouldReceive('findFirstWhere')->andReturn(null);
        $this->app->instance(OrganizerPayRamAccountsRepositoryInterface::class, $accounts);

        $organizers = Mockery::mock(OrganizerRepositoryInterface::class);
        $organizers->shouldNotReceive('findById');
        $this->app->instance(OrganizerRepositoryInterface::class, $organizers);

        $this->service()->syncForOrganizer(29);

        $this->addToAssertionCount(1); // the Mockery expectation is the assertion
    }

    private function service(): PayRamProjectProfileSyncService
    {
        return app(PayRamProjectProfileSyncService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
