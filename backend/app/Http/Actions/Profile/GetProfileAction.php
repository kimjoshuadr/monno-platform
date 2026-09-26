<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\User;
use HiEvents\Models\UserFollow;
use HiEvents\Resources\User\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The buyer's profile payload: identity, the public-facing profile fields, and
 * the three summary counts the dashboard's stat row needs — so the card grid
 * costs one round trip rather than four.
 *
 * Scoped purely by user_id; never touches account context, which a ticket
 * buyer does not have.
 */
class GetProfileAction extends BaseAction
{
    public function __invoke(): JsonResponse
    {
        $userId = $this->getAuthenticatedUser()->getId();
        $user = User::query()->findOrFail($userId);

        $data = (new UserResource(UserDomainObject::hydrateFromModel($user)))->toArray(request());

        $data['headline'] = $user->headline;
        $data['bio'] = $user->bio;
        $data['location'] = $user->location;
        $data['counts'] = [
            // Counted *through* the order rather than by attendees.user_id:
            // only orders carry the buyer link (set on creation and by the
            // email backfill), so going via orders needs no second mechanism.
            'tickets' => (int) DB::table('attendees')
                ->join('orders', 'orders.id', '=', 'attendees.order_id')
                ->where('orders.user_id', $userId)
                ->whereNull('orders.deleted_at')
                ->whereNull('attendees.deleted_at')
                ->count(),
            'events' => DB::table('orders')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->distinct()
                ->count('event_id'),
            'rooms' => UserFollow::where('user_id', $userId)->count(),
        ];

        return $this->jsonResponse(['data' => $data]);
    }
}
