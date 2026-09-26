import {t} from "@lingui/macro";
import {RoomDay, roomDateParts} from "../../../utilites/roomData.ts";
import {RoomEventCard} from "./RoomEventCard.tsx";

interface TimelineProps {
    days: RoomDay[];
    categories: string[];
}

/**
 * monno's timeline: one section per date, a sticky date column, a dashed rail through the
 * column gap and a dot marking each day on it.
 */
export const RoomTimeline = ({days, categories}: TimelineProps) => (
    <div className="cal-timeline" data-od-id="organizer-timeline">
        {days.map((day) => {
            const parts = roomDateParts(day.date);

            return (
                <section
                    className="cal-day"
                    key={day.date}
                    aria-label={`${parts.full} — ${day.events.length} ${day.events.length === 1 ? t`event` : t`events`}`}
                    data-od-id={`cal-day-${day.date}`}
                >
                    <div className="cal-day-title">
                        <span className="d" aria-hidden="true">{parts.day}</span>
                        <span className="w" aria-hidden="true">{parts.weekday}</span>
                    </div>

                    <span className="cal-day-line" aria-hidden="true"/>
                    <span className="cal-day-dot" aria-hidden="true"/>

                    <div className="cal-day-cards room-reveal">
                        {day.events.map((event) => (
                            <RoomEventCard key={event.key} event={event} categories={categories}/>
                        ))}
                    </div>
                </section>
            );
        })}
    </div>
);
