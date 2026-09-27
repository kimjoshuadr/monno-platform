<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Og;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Infrastructure\OgImage\OgImageService;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * `GET /public/og/city/{city}` — a card naming the city, over the cover of an
 * event happening there. 404s for a city no LIVE event is in.
 */
class GetCityOgImagePublicAction extends BaseAction
{
    use RendersOgImages;

    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OgImageService $og,
    ) {}

    public function __invoke(string $city): Response
    {
        $wanted = Str::slug($city);

        foreach ($this->liveEvents() as $event) {
            $address = $event->getEventLocation()?->getLocation()?->getStructuredAddress();
            $name = is_array($address) ? ($address['city'] ?? null) : null;

            if ($name === null || Str::slug($name) !== $wanted) {
                continue;
            }

            $image = $this->ogFirstImage($event->getImages()?->all() ?? [], ['EVENT_IMAGE', 'EVENT_COVER']);

            return $this->ogPng($this->og->render($name, 'Events in', $this->ogBytes($image), true));
        }

        return response('', 404);
    }
}
