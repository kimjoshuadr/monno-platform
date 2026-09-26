<?php

namespace HiEvents\Http\Actions\Organizers;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Organizer\UpsertOrganizerRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Organizer\OrganizerResource;
use HiEvents\Services\Application\Handlers\Organizer\CreateOrganizerHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\CreateOrganizerDTO;
use HiEvents\Services\Domain\Account\AccountPromotionService;
use Illuminate\Http\JsonResponse;

class CreateOrganizerAction extends BaseAction
{
    public function __construct(
        private readonly CreateOrganizerHandler $createOrganizerHandler,
        private readonly AccountPromotionService $accountPromotionService,
    ) {}

    public function __invoke(UpsertOrganizerRequest $request): JsonResponse
    {
        $accountId = $this->getAuthenticatedAccountIdOrNull();

        // First organizer for a monno ticket buyer: they signed up as a bare
        // users row with no account, which is why every organizer endpoint
        // refused them. Give them a tenant first, then create the organizer
        // under it — one identity instead of a second signup.
        if ($accountId === null) {
            $accountId = $this->accountPromotionService->promote($this->getAuthenticatedUser());
        }

        $organizerData = array_merge(
            $request->validated(),
            [
                'account_id' => $accountId,
            ]
        );

        $organizer = $this->createOrganizerHandler->handle(
            organizerData: CreateOrganizerDTO::fromArray($organizerData),
        );

        return $this->resourceResponse(
            resource: OrganizerResource::class,
            data: $organizer,
            statusCode: ResponseCodes::HTTP_CREATED,
        );
    }
}
