<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Profile\ReviewRequest;
use HiEvents\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EditMyReviewAction extends BaseAction
{
    public function __invoke(ReviewRequest $request, int $reviewId): JsonResponse
    {
        $userId = $this->getAuthenticatedUser()->getId();

        // Scoped to the reviewer: another buyer's review id must404, not 403,
        // so it never leaks that the id exists.
        $review = Review::query()
            ->where('id', $reviewId)
            ->where('user_id', $userId)
            ->first();

        if ($review === null) {
            return $this->notFoundResponse();
        }

        $review->update([
            'rating' => (int) $request->validated('rating'),
            'body' => $request->validated('body'),
        ]);

        return $this->jsonResponse([
            'data' => [
                'id' => (int) $review->id,
                'event_id' => (int) $review->event_id,
                'rating' => (int) $review->rating,
                'body' => $review->body,
            ],
        ]);
    }
}
