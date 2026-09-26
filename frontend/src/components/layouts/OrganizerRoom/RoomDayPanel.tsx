import {Link} from "react-router";
import {t} from "@lingui/macro";
import {IconX} from "@tabler/icons-react";
import {RoomEvent, roomCategoryColour, roomDateParts, roomPriceLabel} from "../../../utilites/roomData.ts";

interface DayPanelProps {
    date: string;
    events: RoomEvent[];
    categories: string[];
    onClose: () => void;
}

/**
 * monno's day panel — what a day picked in the month grid opens into. It sits beside the
 * grid, so choosing a date never sends you elsewhere to find out what it did; each event
 * row is a link.
 */
export const RoomDayPanel = ({date, events, categories, onClose}: DayPanelProps) => {
    const parts = roomDateParts(date);

    return (
        <section className="cal-day-panel" aria-label={parts.full} data-od-id="organizer-day-panel">
            <header className="cal-day-panel-head">
                <div>
                    <p className="cal-day-panel-eyebrow">{parts.weekday}</p>
                    <h2 className="cal-day-panel-title">
                        {parts.monthLong} {parts.day}
                    </h2>
                </div>
                <button
                    type="button"
                    className="cal-day-panel-close"
                    onClick={onClose}
                    aria-label={t`Close the day panel`}
                    data-od-id="day-panel-close"
                >
                    <IconX size={16}/>
                </button>
            </header>

            <p className="cal-day-panel-count">
                {events.length} {events.length === 1 ? t`event` : t`events`}
            </p>

            {events.length === 0 ? (
                <p className="cal-day-panel-empty">{t`Nothing on this day.`}</p>
            ) : (
                <ul className="cal-day-panel-list">
                    {events.map((event) => (
                        <li key={event.key}>
                            <Link className="cal-day-panel-item" to={event.link}>
                                <span className="cal-day-panel-thumb">
                                    {event.image ? (
                                        <img src={event.image} alt="" width={128} height={128} loading="lazy" decoding="async"/>
                                    ) : null}
                                </span>
                                <span className="cal-day-panel-body">
                                    <span className="cal-day-panel-meta">
                                        <span
                                            className="cal-day-panel-dot"
                                            style={{background: roomCategoryColour(categories, event.category)}}
                                            aria-hidden="true"
                                        />
                                        {event.start}
                                        <span className="dot-sep"/>
                                        {roomPriceLabel(event.price, event.currency)}
                                    </span>
                                    <span className="cal-day-panel-name">{event.title}</span>
                                    <span className="cal-day-panel-venue">
                                        {[event.venue, event.city].filter(Boolean).join(" · ")}
                                    </span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
};
