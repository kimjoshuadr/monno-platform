<?php

namespace HiEvents\Http\Actions\Organizers\PayRam;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Organizer\Payment\PayRam\GetOrProvisionPayRamAccountHandler;
use Illuminate\Http\JsonResponse;

/**
 * GET → report this organizer's PayRam account without creating one.
 */
class GetPayRamAccountAction extends BaseAction
{
    public function __construct(
        private readonly GetOrProvisionPayRamAccountHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class, Role::ADMIN);

        return $this->jsonResponse($this->handler->handle($organizerId, provision: false));
    }
}
