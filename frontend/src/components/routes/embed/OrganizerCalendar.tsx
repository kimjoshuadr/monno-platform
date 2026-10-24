import {useMemo} from "react";
import {useLocation, useParams} from "react-router";
import {t} from "@lingui/macro";
import {Loader} from "@mantine/core";
import {useGetOrganizerPublic} from "../../../queries/useGetOrganizerPublic.ts";
import {useGetOrganizerPublicEvents} from "../../../queries/useGetOrganizerEventsPublic.ts";
import {EmbedFrame, openEmbedCheckout} from "../../common/EmbedFrame";
import {roomDateParts, roomPriceLabel, toRoomEvents, type RoomEvent} from "../../../utilites/roomData.ts";
import {monnoEventUrl, monnoOrganizerUrl} from "../../../utilites/monnoSite.ts";

type CalendarRow = RoomEvent & { eventId: number | string };

/**
 * The organizer calendar embed: every upcoming date, soonest first, as a list
 * (date · title · time · price · action) or as cards. Each date opens checkout
 * in the host page's modal. "See all on monno" leads to the website room.
 */
const OrganizerCalendarEmbed = () => {
    const {organizerId} = useParams();
    const location = useLocation();
    const params = new URLSearchParams(location.search);
    const layout = params.get('Layout') === 'cards' ? 'cards' : 'list';
    const limit = Math.min(Math.max(Number(params.get('Limit')) || 6, 1), 24);

    const {data: organizer} = useGetOrganizerPublic(organizerId);
    const {data: page, isFetched, isError} = useGetOrganizerPublicEvents(organizerId, {
        pageNumber: 1,
        perPage: 50,
        sortBy: 'start_date',
        sortDirection: 'asc',
        additionalParams: {eventsStatus: 'upcoming'},
        filterFields: {},
    });

    const rows: CalendarRow[] = useMemo(() => {
        const now = Date.now();
        return (page?.data ?? [])
            .flatMap((event) => toRoomEvents(event).map((row) => ({...row, eventId: event.id as number})))
            .filter((row) => row.startsAt && new Date(row.startsAt).getTime() > now)
            .sort((a, b) => a.startsAt.localeCompare(b.startsAt))
            .slice(0, limit);
    }, [page, limit]);

    const roomUrl = monnoOrganizerUrl(organizerId);

    if (!isFetched) {
        return <EmbedFrame><div style={{display: 'grid', placeItems: 'center', minHeight: 160}}><Loader size="sm" type="dots"/></div></EmbedFrame>;
    }

    const buy = (row: CalendarRow) => !row.soldOut && openEmbedCheckout(row.eventId, monnoEventUrl(row.eventId));

    return (
        <EmbedFrame>
            <div className="e-cal-head">
                <h1 className="e-title">{organizer?.name ? t`Upcoming from ${organizer.name}` : t`Upcoming events`}</h1>
            </div>

            {isError || rows.length === 0 ? (
                <div className="e-cal-empty">
                    <p className="e-muted">{t`No upcoming dates right now.`}</p>
                    {roomUrl ? (
                        <a className="e-pill" href={roomUrl} target="_blank" rel="noreferrer">
                            {t`Follow on monno`}
                        </a>
                    ) : null}
                </div>
            ) : layout === 'cards' ? (
                <ul className="e-cal-grid">
                    {rows.map((row) => {
                        const date = roomDateParts(row.date);
                        return (
                            <li key={row.key} className="e-card">
                                <div className="e-card-cover">
                                    {row.image ? <img src={row.image} alt="" loading="lazy"/> : null}
                                    <span className="e-chip" aria-hidden="true"><span>{date.month}</span><strong>{date.day}</strong></span>
                                </div>
                                <div className="e-card-body">
                                    <h2 className="e-title" style={{fontSize: 17}}>{row.title}</h2>
                                    <p className="e-card-meta e-muted">{date.weekday} · {row.start}{row.city ? ` · ${row.city}` : ''}</p>
                                    <div className="e-card-foot">
                                        <span className="e-price">{row.soldOut ? t`Sold out` : roomPriceLabel(row.price, row.currency)}</span>
                                        <button type="button" className="e-pill" aria-disabled={row.soldOut} onClick={() => buy(row)}>
                                            {t`Tickets`}
                                        </button>
                                    </div>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            ) : (
                <ul className="e-cal-list">
                    {rows.map((row) => {
                        const date = roomDateParts(row.date);
                        return (
                            <li key={row.key} className="e-cal-row">
                                <span className="e-chip" aria-hidden="true"><span>{date.month}</span><strong>{date.day}</strong></span>
                                <div>
                                    <h2 className="e-title">{row.title}</h2>
                                    <p className="e-cal-meta e-muted">
                                        {date.weekday} · {row.start}{row.venue ? ` · ${row.venue}` : ''} · {row.soldOut ? t`Sold out` : roomPriceLabel(row.price, row.currency)}
                                    </p>
                                </div>
                                <button type="button" className="e-pill" aria-disabled={row.soldOut} onClick={() => buy(row)}>
                                    {row.soldOut ? t`Sold out` : t`Tickets`}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}

            {roomUrl && rows.length > 0 ? (
                <a className="e-cal-more" href={roomUrl} target="_blank" rel="noreferrer">{t`See all on monno`}</a>
            ) : null}
        </EmbedFrame>
    );
};

export default OrganizerCalendarEmbed;
