<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Profile;

use HiEvents\Http\Actions\BaseAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * A buyer's tickets, split into upcoming and past.
 *
 * The date is not on `orders` or even reliably on `events` — `events.start_date`
 * is null for recurring and single-occurrence events alike — it lives on
 * `event_occurrences`. A ticket is "upcoming" when its event still has an
 * occurrence ahead of now, and past once every occurrence has been.
 */
class GetMyTicketsAction extends BaseAction
{
    public function __invoke(): JsonResponse
    {
        $userId = $this->getAuthenticatedUser()->getId();
        $wantsPast = request()->query('state') === 'past';
        $now = now()->toDateTimeString();

        $rows = DB::select(
            <<<'SQL'
            select o.id,
                   o.short_id,
                   o.status,
                   o.payment_status,
                   o.currency,
                   o.total_gross,
                   o.created_at,
                   e.id         as event_id,
                   e.title      as event_title,
                   e.location   as event_location,
                   e.category   as event_category,
                   e.short_id   as event_short_id,
                   (select min(oc.start_date)
                      from event_occurrences oc
                     where oc.event_id = e.id
                       and oc.deleted_at is null
                       and oc.start_date >= ?) as next_start,
                   (select max(oc.start_date)
                      from event_occurrences oc
                     where oc.event_id = e.id
                       and oc.deleted_at is null
                       and oc.start_date < ?) as last_start,
                   (select count(*)
                      from attendees a
                     where a.order_id = o.id
                       and a.deleted_at is null) as ticket_count
              from orders o
              join events e on e.id = o.event_id
             where o.user_id = ?
               and o.deleted_at is null
               and e.deleted_at is null
            order by o.id desc
            SQL,
            [$now, $now, $userId]
        );

        $tickets = [];
        foreach ($rows as $row) {
            $isUpcoming = $row->next_start !== null;
            if ($isUpcoming === $wantsPast) {
                continue;
            }

            $tickets[] = [
                'id' => (int) $row->id,
                'pass_id' => $row->short_id,
                'status' => $row->status,
                'payment_status' => $row->payment_status,
                'currency' => $row->currency,
                'total' => (float) $row->total_gross,
                'purchased_at' => $row->created_at,
                'ticket_count' => (int) $row->ticket_count,
                'event' => [
                    'id' => (int) $row->event_id,
                    'short_id' => $row->event_short_id,
                    'title' => $row->event_title,
                    'location' => $row->event_location,
                    'category' => $row->event_category,
                    'starts_at' => $isUpcoming ? $row->next_start : $row->last_start,
                ],
            ];
        }

        return $this->jsonResponse(['data' => $tickets, 'meta' => ['state' => $wantsPast ? 'past' : 'upcoming']]);
    }
}
