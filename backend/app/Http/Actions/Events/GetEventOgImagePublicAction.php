<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Events;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Http\Actions\Og\RendersOgImages;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Infrastructure\OgImage\OgImageService;
use Illuminate\Http\Response;

/**
 * `GET /public/og/event/{event_id}` — the monno-branded Open Graph card for an
 * event. LIVE only, and it reads the repository directly so fetching it never
 * counts as a page view.
 */
class GetEventOgImagePublicAction extends BasePublicEventAction
{
    use RendersOgImages;

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

        $image = $this->ogFirstImage($event->getImages()?->all() ?? [], ['EVENT_IMAGE', 'EVENT_COVER']);
        $kicker = $event->getOrganizer()?->getName();

        return $this->ogPng($this->og->render((string) $event->getTitle(), $kicker, $this->ogBytes($image), true));
    }
}
