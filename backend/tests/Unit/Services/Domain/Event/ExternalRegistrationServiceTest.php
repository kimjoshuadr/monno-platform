<?php

namespace Tests\Unit\Services\Domain\Event;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Domain\Event\ExternalRegistrationService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class ExternalRegistrationServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_reports_external_ticketing_when_mode_and_url_are_set(): void
    {
        $service = $this->serviceReturning(
            (new EventSettingDomainObject)
                ->setTicketingMode('EXTERNAL')
                ->setExternalTicketUrl('https://tickets.example.com/my-event')
        );

        $this->assertTrue($service->isExternalTicketing(1));
    }

    public function test_reports_platform_ticketing_by_default(): void
    {
        $service = $this->serviceReturning(new EventSettingDomainObject);

        $this->assertFalse($service->isExternalTicketing(1));
    }

    public function test_reports_platform_ticketing_when_settings_are_missing(): void
    {
        $service = $this->serviceReturning(null);

        $this->assertFalse($service->isExternalTicketing(1));
    }

    public function test_exposes_the_settings_for_the_event(): void
    {
        $settings = (new EventSettingDomainObject)
            ->setTicketingMode('EXTERNAL')
            ->setExternalTicketUrl('https://tickets.example.com/my-event');

        $service = $this->serviceReturning($settings);

        $this->assertSame($settings, $service->getEventSettings(1));
    }

    private function serviceReturning(?EventSettingDomainObject $settings): ExternalRegistrationService
    {
        $repository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $repository->shouldReceive('findFirstWhere')
            ->with(['event_id' => 1])
            ->andReturn($settings);

        return new ExternalRegistrationService($repository);
    }
}
