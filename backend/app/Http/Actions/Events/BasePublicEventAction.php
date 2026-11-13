<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Events;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

abstract class BasePublicEventAction extends BaseAction
{
    protected function canUserViewEvent(EventDomainObject $event): bool
    {
        if ($event->getStatus() === EventStatus::LIVE->name) {
            return true;
        }

        if ($this->isUserAuthenticated() && $event->getAccountId() === $this->getAuthenticatedAccountId()) {
            return true;
        }

        if ($this->isUserAuthenticated() && $this->getAuthenticatedUserRole() === Role::SUPERADMIN) {
            Log::debug(__('Superadmin user is viewing non-live event with ID :eventId', [
                'eventId' => $event->getId(),
                'accountId' => $this->getAuthenticatedAccountId(),
            ]));

            return true;
        }

        return false;
    }

    /**
     * 409 for any public ticketing surface on an event whose tickets are sold
     * by the organizer elsewhere. Carries the URL so clients can point at it.
     */
    protected function externalTicketingResponse(EventSettingDomainObject $settings): JsonResponse
    {
        return $this->errorResponse(
            message: __('Tickets for this event are sold by the organizer'),
            statusCode: ResponseCodes::HTTP_CONFLICT,
            errors: ['external_registration_url' => $settings->getExternalTicketUrl()],
        );
    }
}
