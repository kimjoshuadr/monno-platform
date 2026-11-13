<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * A buyer's saved events, newest first.
 *
 * The date is not on `events` — it lives on `event_occurrences` (see
 * GetMyTicketsAction) — so each row carries the next occurrence when there is
 * one and the last one once the event has passed, which is what the profile
 * card shows.
 */
class GetMySavedEventsAction extends BaseAction
{
    public function __invoke(): JsonResponse
    {
        $userId = $this->getAuthenticatedUser()->getId();
        $now = now()->toDateTimeString();

        $rows = DB::select(
            <<<'SQL'
            select f.event_id,
                   f.created_at as saved_at,
                   e.title,
                   e.short_id,
                   e.location,
                   e.category,
                   (select min(oc.start_date)
                      from event_occurrences oc
                     where oc.event_id = e.id
                       and oc.deleted_at is null
                       and oc.start_date >= ?) as next_start,
                   (select max(oc.start_date)
                      from event_occurrences oc
                     where oc.event_id = e.id
                       and oc.deleted_at is null
                       and oc.start_date < ?) as last_start
              from user_saved_events f
              join events e on e.id = f.event_id
             where f.user_id = ?
               and e.deleted_at is null
             order by f.created_at desc, f.id desc
            SQL,
            [$now, $now, $userId]
        );

        $saved = [];
        foreach ($rows as $row) {
            $saved[] = [
                'event_id' => (int) $row->event_id,
                'saved_at' => $row->saved_at,
                'title' => $row->title,
                'short_id' => $row->short_id,
                'location' => $row->location,
                'category' => $row->category,
                'starts_at' => $row->next_start ?? $row->last_start,
            ];
        }

        return $this->jsonResponse(['data' => $saved]);
    }
}
