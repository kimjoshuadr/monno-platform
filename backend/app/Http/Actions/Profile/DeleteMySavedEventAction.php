<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\UserSavedEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class DeleteMySavedEventAction extends BaseAction
{
    public function __invoke(int $eventId): Response|JsonResponse
    {
        $deleted = UserSavedEvent::query()
            ->where('user_id', $this->getAuthenticatedUser()->getId())
            ->where('event_id', $eventId)
            ->delete();

        if ($deleted === 0) {
            return $this->notFoundResponse();
        }

        return $this->deletedResponse();
    }
}
