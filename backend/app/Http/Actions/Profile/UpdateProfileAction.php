<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Profile\ProfileRequest;
use HiEvents\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Writes the three public profile fields together. monno always sends the
 * complete set, so an omitted key and a cleared value never diverge — and this
 * endpoint is deliberately separate from PUT /users/me, whose validation would
 * otherwise let an organizer profile edit wipe a buyer's bio.
 */
class UpdateProfileAction extends BaseAction
{
    public function __invoke(ProfileRequest $request): JsonResponse
    {
        $userId = $this->getAuthenticatedUser()->getId();

        User::query()->whereKey($userId)->update([
            'headline' => $request->validated('headline'),
            'bio' => $request->validated('bio'),
            'location' => $request->validated('location'),
        ]);

        return $this->jsonResponse(['data' => ['updated' => true]]);
    }
}
