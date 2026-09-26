<?php

namespace HiEvents\Http\Actions\Organizers\Public;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrganizerStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\DTO\SendOrganizerContactMessageDTO;
use HiEvents\Services\Application\Handlers\Organizer\SendOrganizerContactMessageHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SendOrganizerContactMessagePublicAction extends BaseAction
{
    public function __construct(
        private readonly SendOrganizerContactMessageHandler $handler,
        private readonly OrganizerRepositoryInterface $organizerRepository,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, int $organizerId): Response|JsonResponse
    {
        $data = $this->validate($request, [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'message' => 'required|string|max:5000',
        ]);

        $isAuthenticated = $this->isUserAuthenticated();
        $organizer = $this->organizerRepository->findById($organizerId);

        // Contacting an unpublished organizer would confirm it exists.
        if (! $this->canUserViewOrganizer($organizer, $isAuthenticated)) {
            return $this->notFoundResponse();
        }

        $this->handler->handle(SendOrganizerContactMessageDTO::from([
            'organizer_id' => $organizerId,
            'account_id' => $isAuthenticated ? $this->getAuthenticatedAccountId() : null,
            'name' => $data['name'],
            'email' => $data['email'],
            'message' => $data['message'],
        ]));

        return $this->jsonResponse([
            'message' => __('Message sent successfully'),
        ]);
    }

    private function canUserViewOrganizer(OrganizerDomainObject $organizer, bool $isAuthenticated): bool
    {
        if ($organizer->getStatus() === OrganizerStatus::LIVE->name) {
            return true;
        }

        return $isAuthenticated && $organizer->getAccountId() === $this->getAuthenticatedAccountId();
    }
}
