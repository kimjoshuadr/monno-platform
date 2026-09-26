<?php

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\EventLocationDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\LocationDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\GetPublicEventsListDTO;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Every LIVE event across every organizer — the feed the website reads to render
 * events it was not built with.
 *
 * The public API previously exposed events only one at a time, or one organizer at
 * a time, so a site could only show events it was hardcoded with. This is the
 * missing list. It is deliberately LIVE-only: a draft must never reach an
 * anonymous visitor, and the website renders exactly this set.
 *
 * The relation set matches `GetPublicEventHandler` (minus occurrences, promo codes
 * and view-counting), so the site can map an event straight onto its own page
 * shape: cover and logo images, venue, organizer, prices and settings.
 */
class GetPublicEventsListHandler
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    public function handle(GetPublicEventsListDTO $dto): LengthAwarePaginator
    {
        $where = [
            EventDomainObjectAbstract::STATUS => EventStatus::LIVE->name,
        ];

        return $this->eventRepository
            // The directory reads the whole LIVE set; the default cap (100) would
            // mean dozens of round trips. 1000 keeps a full read to a couple of
            // requests.
            ->setMaxPerPage(1000)
            ->loadRelation(
                new Relationship(ProductCategoryDomainObject::class, [
                    new Relationship(ProductDomainObject::class,
                        nested: [
                            new Relationship(ProductPriceDomainObject::class),
                            new Relationship(TaxAndFeesDomainObject::class),
                        ],
                        orderAndDirections: [
                            new OrderAndDirection('order', 'asc'),
                        ]
                    ),
                ])
            )
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            // Dates are computed from occurrences, not the event row: without them
            // the card has no date at all (see EventDomainObject::getStartDate()).
            ->loadRelation(new Relationship(EventOccurrenceDomainObject::class))
            ->loadRelation(new Relationship(domainObject: EventLocationDomainObject::class, name: 'event_location', nested: [
                new Relationship(domainObject: LocationDomainObject::class, name: 'location'),
            ]))
            ->loadRelation(new Relationship(ImageDomainObject::class))
            ->loadRelation(new Relationship(OrganizerDomainObject::class, nested: [
                new Relationship(ImageDomainObject::class),
                new Relationship(OrganizerSettingDomainObject::class),
                new Relationship(domainObject: LocationDomainObject::class, name: 'location_record'),
            ], name: 'organizer'))
            ->findEvents(
                where: $where,
                params: $dto->queryParams,
            );
    }
}
