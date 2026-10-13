<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Admin\Events;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Feature or un-feature an event.
 *
 * Featuring drives the hero floats and the featured rail on the public site,
 * so it is the platform's editorial call — superadmins tick it, organizers
 * cannot (the field was dropped from their event rules; creates default to
 * false and updates leave it untouched).
 */
class SetEventFeaturedAction extends BaseAction
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $this->minimumAllowedRole(Role::SUPERADMIN);

        $validated = $request->validate([
            'featured' => 'required|boolean',
        ]);

        $this->eventRepository->updateWhere(
            attributes: ['featured' => (bool) $validated['featured']],
            where: ['id' => $eventId],
        );

        return $this->jsonResponse(['data' => [
            'id' => $eventId,
            'featured' => (bool) $validated['featured'],
        ]]);
    }
}
