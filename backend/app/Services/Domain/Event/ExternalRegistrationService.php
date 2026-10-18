<?php

namespace HiEvents\Services\Domain\Event;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;

/**
 * Resolves whether an event sells its tickets through the platform or hands
 * buyers off to the organizer's own registration surface. The public order,
 * waitlist and promo-code endpoints use this to hard-block platform ticketing
 * for external-registration events.
 */
class ExternalRegistrationService
{
    public function __construct(
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
    ) {}

    public function getEventSettings(int $eventId): ?EventSettingDomainObject
    {
        return $this->eventSettingsRepository->findFirstWhere([
            'event_id' => $eventId,
        ]);
    }

    public function isExternalTicketing(int $eventId): bool
    {
        return (bool) $this->getEventSettings($eventId)?->isTicketingExternal();
    }
}
