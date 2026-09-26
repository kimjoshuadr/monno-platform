<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Events;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Infrastructure\OgImage\OgImageService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * `GET /public/og/event/{event_id}` — the monno-branded Open Graph card for an
 * event. LIVE only, and it reads the repository directly so fetching it never
 * counts as a page view.
 */
class GetEventOgImagePublicAction extends BasePublicEventAction
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OgImageService $og,
    ) {}

    public function __invoke(int $eventId): Response
    {
        $event = $this->eventRepository
            ->loadRelation(new Relationship(ImageDomainObject::class))
            ->loadRelation(new Relationship(OrganizerDomainObject::class, nested: [
                new Relationship(ImageDomainObject::class),
            ], name: 'organizer'))
            ->findById($eventId);

        if (! $event instanceof EventDomainObject || $event->getStatus() !== EventStatus::LIVE->name) {
            return response('', 404);
        }

        $image = $this->firstImage($event->getImages()?->all() ?? [], ['EVENT_IMAGE', 'EVENT_COVER']);
        $kicker = $event->getOrganizer()?->getName();

        $png = $this->og->render((string) $event->getTitle(), $kicker, $this->bytes($image));

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * @param  ImageDomainObject[]  $images
     * @param  string[]  $types
     */
    private function firstImage(array $images, array $types): ?ImageDomainObject
    {
        foreach ($types as $type) {
            foreach ($images as $image) {
                if ($image->getType() === $type) {
                    return $image;
                }
            }
        }

        return null;
    }

    private function bytes(?ImageDomainObject $image): ?string
    {
        if ($image === null || $image->getPath() === null) {
            return null;
        }

        try {
            return Storage::disk($image->getDisk() ?: config('filesystems.public'))->get($image->getPath());
        } catch (\Throwable) {
            return null;
        }
    }
}
