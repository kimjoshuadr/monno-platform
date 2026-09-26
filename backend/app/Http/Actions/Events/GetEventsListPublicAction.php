<?php

namespace HiEvents\Http\Actions\Events;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Event\EventResourcePublic;
use HiEvents\Resources\Event\PublicEventCardResource;
use HiEvents\Services\Application\Handlers\Event\DTO\GetPublicEventsListDTO;
use HiEvents\Services\Application\Handlers\Event\GetPublicEventsListHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /public/events` — the LIVE event feed the website renders events from.
 *
 * `?view=card` returns the slim directory projection; the default is the full
 * per-event resource (use `view=card` for lists).
 */
class GetEventsListPublicAction extends BaseAction
{
    public function __construct(
        private readonly GetPublicEventsListHandler $handler,
    ) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        $events = $this->handler->handle(new GetPublicEventsListDTO(
            queryParams: $this->getPaginationQueryParams($request),
        ));

        $resource = $request->string('view')->toString() === 'card'
            ? PublicEventCardResource::class
            : EventResourcePublic::class;

        return $this->resourceResponse($resource, $events);
    }
}
