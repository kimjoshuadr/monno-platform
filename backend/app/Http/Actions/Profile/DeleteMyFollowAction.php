<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\UserFollow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class DeleteMyFollowAction extends BaseAction
{
    public function __invoke(int $organizerId): Response|JsonResponse
    {
        $deleted = UserFollow::query()
            ->where('user_id', $this->getAuthenticatedUser()->getId())
            ->where('organizer_id', $organizerId)
            ->delete();

        if ($deleted === 0) {
            return $this->notFoundResponse();
        }

        return $this->deletedResponse();
    }
}
