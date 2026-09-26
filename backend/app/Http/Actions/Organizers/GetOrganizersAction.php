<?php

namespace HiEvents\Http\Actions\Organizers;

use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Resources\Organizer\OrganizerResource;
use Illuminate\Http\JsonResponse;

class GetOrganizersAction extends BaseAction
{
    public function __construct(private readonly OrganizerRepositoryInterface $organizerRepository) {}

    public function __invoke(): JsonResponse
    {
        $accountId = $this->getAuthenticatedAccountIdOrNull();

        // A ticket buyer belongs to no account, so they have no organizers —
        // an empty list is the truthful answer. 403 here made the frontend's
        // axios interceptor bounce them to /auth/login, which then bounced
        // them back to onboarding in a loop until the API started throttling.
        if ($accountId === null) {
            return $this->resourceResponse(
                resource: OrganizerResource::class,
                data: collect(),
            );
        }

        $organizers = $this->organizerRepository
            ->loadRelation(ImageDomainObject::class)
            ->findwhere([
                'account_id' => $accountId,
            ]);

        return $this->resourceResponse(
            resource: OrganizerResource::class,
            data: $organizers,
        );
    }
}
