import {useParams} from "react-router";
import {t} from "@lingui/macro";
import {Loader} from "@mantine/core";
import {useGetEventPublic} from "../../../queries/useGetEventPublic.ts";
import {EmbedFrame, openEmbedCheckout} from "../../common/EmbedFrame";
import {roomDateParts, roomPriceLabel, toRoomEvents} from "../../../utilites/roomData.ts";
import {monnoEventUrl} from "../../../utilites/monnoSite.ts";

/**
 * The event card embed: photograph, date, title, place and price, with one
 * action. Buying opens the on-page checkout modal on the host's site; a past or
 * sold-out event says so and offers nothing to buy.
 */
const EventCardEmbed = () => {
    const {eventId} = useParams();
    const {data: event, isFetched, isError} = useGetEventPublic(eventId);

    if (!isFetched) {
        return <EmbedFrame><div style={{display: 'grid', placeItems: 'center', minHeight: 200}}><Loader size="sm" type="dots"/></div></EmbedFrame>;
    }

    if (isError || !event) {
        return <EmbedFrame><p className="e-muted" style={{padding: 16, margin: 0}}>{t`This event is not available.`}</p></EmbedFrame>;
    }

    const now = Date.now();
    const rows = toRoomEvents(event);
    const next = rows.find((row) => new Date(row.startsAt).getTime() > now) ?? rows[rows.length - 1];
    const ended = !next || new Date(next.startsAt).getTime() < now;
    const soldOut = !!next?.soldOut;
    const date = next ? roomDateParts(next.date) : null;
    const place = [next?.venue, next?.city].filter(Boolean).join(' · ');
    const canBuy = !ended && !soldOut;

    return (
        <EmbedFrame>
            <article className="e-card">
                <div className="e-card-cover">
                    {next?.image ? <img src={next.image} alt="" loading="lazy"/> : null}
                    {date ? (
                        <span className="e-chip" aria-hidden="true">
                            <span>{date.month}</span>
                            <strong>{date.day}</strong>
                        </span>
                    ) : null}
                </div>
                <div className="e-card-body">
                    <h1 className="e-title">{event.title}</h1>
                    {date ? (
                        <p className="e-card-meta e-muted">
                            {date.full}{next?.start ? ` · ${next.start}` : ''}
                        </p>
                    ) : null}
                    {place ? <p className="e-card-meta e-muted">{place}</p> : null}
                    <div className="e-card-foot">
                        <span className="e-price">
                            {ended ? <span className="e-badge">{t`Ended`}</span>
                                : soldOut ? <span className="e-badge">{t`Sold out`}</span>
                                    : next ? roomPriceLabel(next.price, next.currency) : null}
                        </span>
                        <button
                            type="button"
                            className="e-pill"
                            aria-disabled={!canBuy}
                            onClick={() => canBuy && event.id && openEmbedCheckout(event.id, monnoEventUrl(event.id))}
                        >
                            {canBuy ? t`Get tickets` : t`See event`}
                        </button>
                    </div>
                </div>
            </article>
        </EmbedFrame>
    );
};

export default EventCardEmbed;
