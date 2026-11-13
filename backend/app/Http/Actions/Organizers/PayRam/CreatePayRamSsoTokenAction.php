<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organizers\PayRam;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Organizer\Payment\PayRam\CreatePayRamSsoTokenHandler;
use Illuminate\Http\JsonResponse;

class CreatePayRamSsoTokenAction extends BaseAction
{
    public function __construct(
        private readonly CreatePayRamSsoTokenHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class, Role::ADMIN);

        return $this->jsonResponse($this->handler->handle($organizerId));
    }
}
