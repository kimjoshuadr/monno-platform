<?php

namespace HiEvents\Http\Actions\Common;

use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;

class GetEventCategoriesPublicAction extends BaseAction
{
    /**
     * Presentation order — mirrors the category picker in the React frontend
     * (frontend/src/constants/eventCategories.ts), which is the order visitors
     * see. The enum's declaration order is grouping-only and differs.
     */
    private const ORDER = [
        EventCategory::NIGHTLIFE,
        EventCategory::FESTIVAL,
        EventCategory::SEASONAL,
        EventCategory::MUSIC,
        EventCategory::SPORTS,
        EventCategory::COMEDY,
        EventCategory::THEATER,
        EventCategory::FILM,
        EventCategory::DANCE,
        EventCategory::ART,
        EventCategory::SOCIAL,
        EventCategory::FAMILY,
        EventCategory::HOBBIES,
        EventCategory::FOOD_DRINK,
        EventCategory::WELLNESS,
        EventCategory::SPIRITUALITY,
        EventCategory::OUTDOORS,
        EventCategory::TOURS,
        EventCategory::CHARITY,
        EventCategory::BUSINESS,
        EventCategory::TECH,
        EventCategory::EDUCATION,
        EventCategory::WORKSHOP,
        EventCategory::OTHER,
    ];

    public function __invoke(): JsonResponse
    {
        $categories = array_map(
            static fn (EventCategory $category): array => [
                'id' => $category->value,
                'label' => $category->label(),
                'emoji' => $category->emoji(),
                'group' => $category->group(),
            ],
            self::ORDER,
        );

        return $this->jsonResponse(
            data: $categories,
            wrapInData: true,
        );
    }
}
