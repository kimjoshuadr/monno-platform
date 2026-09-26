<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Models\Review;
use Illuminate\Http\JsonResponse;

class GetMyReviewsAction extends BaseAction
{
    public function __invoke(): JsonResponse
    {
        $userId = $this->getAuthenticatedUser()->getId();

        $reviews = Review::query()
            ->where('reviews.user_id', $userId)
            ->join('events', 'events.id', '=', 'reviews.event_id')
            ->whereNull('events.deleted_at')
            ->select('reviews.*', 'events.title as event_title', 'events.short_id as event_short_id')
            ->orderByDesc('reviews.created_at')
            ->get()
            ->map(fn (Review $review) => [
                'id' => (int) $review->id,
                'event_id' => (int) $review->event_id,
                'rating' => (int) $review->rating,
                'body' => $review->body,
                'created_at' => $review->created_at,
                'event' => [
                    'id' => (int) $review->event_id,
                    'short_id' => $review->event_short_id,
                    'title' => $review->event_title,
                ],
            ])
            ->values();

        return $this->jsonResponse(['data' => $reviews]);
    }
}
