<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class DeleteMyReviewAction extends BaseAction
{
    public function __invoke(int $reviewId): Response|JsonResponse
    {
        $deleted = Review::query()
            ->where('id', $reviewId)
            ->where('user_id', $this->getAuthenticatedUser()->getId())
            ->delete();

        if ($deleted === 0) {
            return $this->notFoundResponse();
        }

        return $this->deletedResponse();
    }
}
