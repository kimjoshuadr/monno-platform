<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\UserInterest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the whole interest set in one write. monno always sends both keys,
 * so absent means "the person removed everything of that kind" rather than
 * "leave it alone" — a partial patch here would make deletions impossible.
 */
class UpdateMyInterestsAction extends BaseAction
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // `present`, not `required`: an empty array is how the caller says
            // "no interests of this kind", and required rejects empty arrays.
            'categories' => ['present', 'array'],
            'categories.*' => ['string', 'max:60'],
            'cities' => ['present', 'array'],
            'cities.*' => ['string', 'max:120'],
        ]);

        $userId = $this->getAuthenticatedUser()->getId();

        DB::transaction(function () use ($userId, $validated) {
            $map = ['category' => $validated['categories'], 'city' => $validated['cities']];

            foreach ($map as $kind => $values) {
                UserInterest::query()->where('user_id', $userId)->where('kind', $kind)->delete();

                foreach (array_unique($values) as $value) {
                    UserInterest::create(['user_id' => $userId, 'kind' => $kind, 'value' => $value]);
                }
            }
        });

        return $this->jsonResponse(['data' => ['updated' => true]]);
    }
}
