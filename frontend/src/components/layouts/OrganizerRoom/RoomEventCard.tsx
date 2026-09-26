import {Link} from "react-router";
import {t} from "@lingui/macro";
import {IconBookmark, IconBookmarkFilled, IconUsers} from "@tabler/icons-react";
import {RoomEvent, roomCategoryColour, roomPriceLabel} from "../../../utilites/roomData.ts";
import {useRoomSaved} from "./useRoomSaved.ts";

interface EventCardProps {
    event: RoomEvent;
    /** Category order, so the day chips share the room's colour ramp. */
    categories: string[];
}

/**
 * monno's event card: one stretched link per event, copy reading from the left with the
 * square cover on the right (`flex-direction: row-reverse`).
 */
export const RoomEventCard = ({event, categories}: EventCardProps) => {
    const {isSaved, toggle, ready} = useRoomSaved();
    const saved = ready && isSaved(event.key);

    return (
        <article className="cal-card" data-od-id={`cal-card-${event.key}`}>
            <Link className="cal-card-link" to={event.link} aria-label={event.title} tabIndex={-1}/>

            <div className="cal-card-info">
                <span className="cal-card-time">{event.start}</span>

                <h3 className="cal-card-title">{event.title}</h3>

                <p className="cal-card-attr">
                    <span
                        className="cal-card-dot"
                        style={{background: roomCategoryColour(categories, event.category)}}
                        aria-hidden="true"
                    />
                    {event.category}
                </p>

                <p className="cal-card-attr">
                    {[event.venue, event.city].filter(Boolean).join(" · ")}
                </p>

                <div className="cal-card-foot">
                    <span className={`badge${event.price === 0 ? " badge-free" : ""}`}>
                        {roomPriceLabel(event.price, event.currency)}
                    </span>
                    {event.soldOut ? (
                        <span className="badge badge-ink">{t`Sold out`}</span>
                    ) : event.spotsLeft !== null && event.spotsLeft <= 10 ? (
                        <span className="badge">
                            {event.spotsLeft} {t`left`}
                        </span>
                    ) : null}

                    <button
                        type="button"
                        className="cal-card-save"
                        aria-pressed={saved}
                        aria-label={saved ? `${t`Saved`}: ${event.title}` : `${t`Save`}: ${event.title}`}
                        onClick={() => toggle(event.key)}
                    >
                        {saved ? <IconBookmarkFilled size={16}/> : <IconBookmark size={16}/>}
                    </button>
                </div>
            </div>

            <div className="cal-card-cover">
                {event.image ? (
                    <img src={event.image} alt="" width={360} height={360} loading="lazy" decoding="async"/>
                ) : (
                    <span className="cal-card-cover-empty" aria-hidden="true">
                        <IconUsers size={22}/>
                    </span>
                )}
            </div>
        </article>
    );
};
