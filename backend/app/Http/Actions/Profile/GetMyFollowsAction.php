<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\UserFollow;
use Illuminate\Http\JsonResponse;

class GetMyFollowsAction extends BaseAction
{
    public function __invoke(): JsonResponse
    {
        $userId = $this->getAuthenticatedUser()->getId();

        $follows = UserFollow::query()
            ->where('user_follows.user_id', $userId)
            ->join('organizers', 'organizers.id', '=', 'user_follows.organizer_id')
            ->whereNull('organizers.deleted_at')
            ->select(
                'user_follows.organizer_id',
                'user_follows.created_at as followed_at',
                'organizers.name as name',
                'organizers.description as description',
                'organizers.website as website',
            )
            ->orderBy('user_follows.created_at')
            ->get()
            ->map(fn ($row) => [
                'organizer_id' => (int) $row->organizer_id,
                'name' => $row->name,
                'description' => $row->description,
                'website' => $row->website,
                'followed_at' => $row->followed_at,
            ])
            ->values();

        return $this->jsonResponse(['data' => $follows]);
    }
}
