<?php

namespace HiEvents\Services\Application\Handlers\Event\DTO;

use HiEvents\DataTransferObjects\BaseDTO;
use HiEvents\Http\DTO\QueryParamsDTO;

/**
 * The public event feed — every LIVE event, paginated.
 *
 * There is no slug filter: an event's slug is derived from its title
 * (`EventDomainObject::getSlug()`), not stored, so it cannot be queried. The
 * website keys a live event by its id and reads `GET /public/events/{id}`.
 */
class GetPublicEventsListDTO extends BaseDTO
{
    public function __construct(
        public QueryParamsDTO $queryParams,
    ) {}
}
