<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\UserFollow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreateMyFollowAction extends BaseAction
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organizer_id' => ['required', 'integer', 'exists:organizers,id'],
        ]);

        // firstOrCreate: tapping follow twice must not trip the unique index.
        $follow = UserFollow::firstOrCreate([
            'user_id' => $this->getAuthenticatedUser()->getId(),
            'organizer_id' => (int) $validated['organizer_id'],
        ]);

        return $this->jsonResponse(
            ['data' => ['organizer_id' => (int) $follow->organizer_id, 'followed_at' => $follow->created_at]],
            201
        );
    }
}
