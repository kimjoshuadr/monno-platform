<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\UserInterest;
use Illuminate\Http\JsonResponse;

class GetMyInterestsAction extends BaseAction
{
    public function __invoke(): JsonResponse
    {
        $rows = UserInterest::query()
            ->where('user_id', $this->getAuthenticatedUser()->getId())
            ->orderBy('value')
            ->get();

        return $this->jsonResponse([
            'data' => [
                'categories' => $rows->where('kind', 'category')->pluck('value')->values(),
                'cities' => $rows->where('kind', 'city')->pluck('value')->values(),
            ],
        ]);
    }
}
