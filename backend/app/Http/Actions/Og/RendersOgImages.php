<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Og;

use HiEvents\DomainObjects\EventLocationDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\LocationDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Eloquent\Value\Relationship;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Shared helpers for the public Open Graph cards: the response shape, reading an
 * image's bytes off the public disk, and the LIVE event set used to find a
 * representative image for a city or category card.
 *
 * Classes using this must expose `$eventRepository` when they call `liveEvents()`.
 */
trait RendersOgImages
{
    protected function ogPng(string $png): Response
    {
        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * @param  ImageDomainObject[]  $images
     * @param  string[]  $types
     */
    protected function ogFirstImage(array $images, array $types): ?ImageDomainObject
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

    protected function ogBytes(?ImageDomainObject $image): ?string
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

    /**
     * LIVE events with the images and location a card needs, newest first.
     *
     * @return array<int, \HiEvents\DomainObjects\EventDomainObject>
     */
    protected function liveEvents(): array
    {
        return $this->eventRepository
            ->loadRelation(new Relationship(ImageDomainObject::class))
            ->loadRelation(new Relationship(EventLocationDomainObject::class, name: 'event_location', nested: [
                new Relationship(LocationDomainObject::class, name: 'location'),
            ]))
            ->findEvents(
                where: ['status' => EventStatus::LIVE->name],
                params: QueryParamsDTO::fromArray(['per_page' => 100]),
            )
            ->items();
    }
}
