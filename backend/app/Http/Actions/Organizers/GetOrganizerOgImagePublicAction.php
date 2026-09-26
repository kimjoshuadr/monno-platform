<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Organizers;

use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrganizerStatus;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Infrastructure\OgImage\OgImageService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * `GET /public/og/organizer/{organizer_id}` — the monno-branded Open Graph card
 * for an organizer room, using the organizer's logo as the 1:1 tile.
 */
class GetOrganizerOgImagePublicAction
{
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

        $logo = null;
        foreach ($organizer->getImages()?->all() ?? [] as $image) {
            if ($image->getType() === 'ORGANIZER_LOGO') {
                $logo = $image;
                break;
            }
        }

        $png = $this->og->render((string) $organizer->getName(), 'Organizer', $this->bytes($logo));

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
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
