<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Profile\ReviewRequest;
use HiEvents\Models\Review;
use Illuminate\Http\JsonResponse;

/**
 * One review per person per event. `updateOrCreate` rather than insert, so
 * re-reviewing an event replaces the old verdict instead of tripping the
 * unique constraint the migration puts on (user_id, event_id).
 */
class CreateMyReviewAction extends BaseAction
{
    public function __invoke(ReviewRequest $request): JsonResponse
    {
        $review = Review::updateOrCreate(
            [
                'user_id' => $this->getAuthenticatedUser()->getId(),
                'event_id' => (int) $request->validated('event_id'),
            ],
            [
                'rating' => (int) $request->validated('rating'),
                'body' => $request->validated('body'),
            ]
        );

        return $this->jsonResponse(
            [
                'data' => [
                    'id' => (int) $review->id,
                    'event_id' => (int) $review->event_id,
                    'rating' => (int) $review->rating,
                    'body' => $review->body,
                ],
            ],
            201
        );
    }
}
