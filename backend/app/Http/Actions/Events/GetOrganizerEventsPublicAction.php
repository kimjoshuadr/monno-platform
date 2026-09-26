<?php

namespace HiEvents\Http\Actions\Events;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrganizerStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Resources\Event\EventResourcePublic;
use HiEvents\Services\Application\Handlers\Event\DTO\GetPublicOrganizerEventsDTO;
use HiEvents\Services\Application\Handlers\Event\GetPublicEventsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GetOrganizerEventsPublicAction extends BaseAction
{
    public function __construct(
        private readonly GetPublicEventsHandler $handler,
        private readonly OrganizerRepositoryInterface $organizerRepository,
    ) {}

    public function __invoke(int $organizerId, Request $request): Response|JsonResponse
    {
        $isAuthenticated = $this->isUserAuthenticated();
        $organizer = $this->organizerRepository->findById($organizerId);

        // A non-live organizer profile is invisible to everyone but its own
        // team — their events must not reveal it either.
        if (! $this->canUserViewOrganizer($organizer, $isAuthenticated)) {
            return $this->notFoundResponse();
        }

        $events = $this->handler->handle(new GetPublicOrganizerEventsDTO(
            organizerId: $organizerId,
            queryParams: $this->getPaginationQueryParams($request),
            authenticatedAccountId: $isAuthenticated ? $this->getAuthenticatedAccountId() : null
        ));

        return $this->resourceResponse(
            resource: EventResourcePublic::class,
            data: $events,
        );
    }

    private function canUserViewOrganizer(OrganizerDomainObject $organizer, bool $isAuthenticated): bool
    {
        if ($organizer->getStatus() === OrganizerStatus::LIVE->name) {
            return true;
        }

        return $isAuthenticated && $organizer->getAccountId() === $this->getAuthenticatedAccountId();
    }
}
