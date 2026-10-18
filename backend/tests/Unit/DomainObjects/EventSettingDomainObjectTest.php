<?php

namespace Tests\Unit\DomainObjects;

use HiEvents\DomainObjects\EventSettingDomainObject;
use Tests\TestCase;

class EventSettingDomainObjectTest extends TestCase
{
    public function test_is_ticketing_external_requires_mode_and_url(): void
    {
        $settings = (new EventSettingDomainObject)
            ->setTicketingMode('EXTERNAL')
            ->setExternalTicketUrl('https://tickets.example.com/my-event');

        $this->assertTrue($settings->isTicketingExternal());
    }

    public function test_is_ticketing_external_is_false_without_a_url(): void
    {
        $settings = (new EventSettingDomainObject)
            ->setTicketingMode('EXTERNAL')
            ->setExternalTicketUrl(null);

        $this->assertFalse(
            $settings->isTicketingExternal(),
            'An EXTERNAL mode without a URL must never redirect buyers off-platform'
        );
    }

    public function test_is_ticketing_external_is_false_for_platform_mode(): void
    {
        $settings = (new EventSettingDomainObject)
            ->setTicketingMode('PLATFORM')
            ->setExternalTicketUrl('https://tickets.example.com/my-event');

        $this->assertFalse($settings->isTicketingExternal());
    }

    public function test_defaults_to_platform_ticketing(): void
    {
        $settings = new EventSettingDomainObject;

        $this->assertSame('PLATFORM', $settings->getTicketingMode());
        $this->assertNull($settings->getExternalTicketUrl());
        $this->assertFalse($settings->isTicketingExternal());
    }
}
