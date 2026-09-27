<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organizers;

use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrganizerStatus;
use HiEvents\Http\Actions\Og\RendersOgImages;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Infrastructure\OgImage\OgImageService;
use Illuminate\Http\Response;

/**
 * `GET /public/og/organizer/{organizer_id}` — the monno-branded Open Graph card
 * for an organizer room, using the organizer's logo as the 1:1 tile.
 */
class GetOrganizerOgImagePublicAction
{
    use RendersOgImages;

    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly OgImageService $og,
    ) {}

    public function __invoke(int $organizerId): Response
    {
        $organizer = $this->organizerRepository
            ->loadRelation(new Relationship(ImageDomainObject::class))
            ->findById($organizerId);

        if (! $organizer instanceof OrganizerDomainObject || $organizer->getStatus() !== OrganizerStatus::LIVE->name) {
            return response('', 404);
        }

        $logo = $this->ogFirstImage($organizer->getImages()?->all() ?? [], ['ORGANIZER_LOGO']);

        return $this->ogPng($this->og->render((string) $organizer->getName(), 'Organizer', $this->ogBytes($logo), false));
    }
}
