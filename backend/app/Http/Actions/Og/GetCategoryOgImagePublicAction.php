<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Og;

use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Infrastructure\OgImage\OgImageService;
use Illuminate\Http\Response;

/**
 * `GET /public/og/category/{category}` — a card naming a category, over the cover
 * of an event in it. The category is the platform's own id (e.g. `FOOD_DRINK`).
 */
class GetCategoryOgImagePublicAction extends BaseAction
{
    use RendersOgImages;

    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OgImageService $og,
    ) {}

    public function __invoke(string $category): Response
    {
        $enum = EventCategory::tryFrom(strtoupper($category));

        if ($enum === null) {
            return response('', 404);
        }

        $image = null;

        foreach ($this->liveEvents() as $event) {
            if ($event->getCategory() !== $enum->value) {
                continue;
            }

            $candidate = $this->ogFirstImage($event->getImages()?->all() ?? [], ['EVENT_IMAGE', 'EVENT_COVER']);

            // Prefer an event in this category that has a cover to show.
            if ($candidate !== null) {
                $image = $candidate;
                break;
            }
        }

        return $this->ogPng($this->og->render($enum->label(), 'Category', $this->ogBytes($image), true));
    }
}
