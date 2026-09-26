import {useMemo, useState} from "react";
import {t} from "@lingui/macro";
import {IconChevronLeft, IconChevronRight} from "@tabler/icons-react";
import dayjs from "dayjs";
import {RoomEvent, roomCategoryColour, roomDateParts, roomTodayKey} from "../../../utilites/roomData.ts";

const WEEKDAYS = Array.from({length: 7}, (_, index) => dayjs().day(index).format("ddd"));

/** Events previewed inside a day cell before the "+N more" hand-off. */
const MAX_CHIPS = 3;

type Cursor = { y: number; m: number };

const iso = (y: number, m: number, d: number) =>
    `${y}-${String(m + 1).padStart(2, "0")}-${String(d).padStart(2, "0")}`;

const step = ({y, m}: Cursor, delta: number): Cursor => {
    const next = dayjs.utc(`${iso(y, m, 1)}`).add(delta, "month");
    return {y: next.year(), m: next.month()};
};

interface MonthViewProps {
    events: RoomEvent[];
    categories: string[];
    selected?: string | null;
    onSelectDay: (date: string | null) => void;
}

/**
 * monno's month view: each dated cell is a day selector that opens that day in the panel
 * beside the grid, so nothing is ever filtered out of sight. The chips inside a cell are
 * inert — the cell is the target.
 */
export const RoomMonthView = ({events, categories, selected, onSelectDay}: MonthViewProps) => {
    const today = roomTodayKey();

    const [cursor, setCursor] = useState<Cursor>(() => {
        const first = events[0]?.date;
        const start = first ? dayjs.utc(first) : dayjs.utc(today);
        return {y: start.year(), m: start.month()};
    });

    const byDate = useMemo(() => {
        const map = new Map<string, RoomEvent[]>();
        for (const event of events) {
            map.set(event.date, [...(map.get(event.date) ?? []), event]);
        }
        for (const list of map.values()) {
            list.sort((a, b) => a.sortTime.localeCompare(b.sortTime));
        }
        return map;
    }, [events]);

    const cells = useMemo(() => {
        const first = dayjs.utc(iso(cursor.y, cursor.m, 1));
        const lead = first.day();
        const total = first.daysInMonth();
        const out: { date: string; inMonth: boolean }[] = [];

        for (let back = lead; back > 0; back -= 1) {
            const day = first.subtract(back, "day");
            out.push({date: day.format("YYYY-MM-DD"), inMonth: false});
        }
        for (let day = 1; day <= total; day += 1) {
            out.push({date: iso(cursor.y, cursor.m, day), inMonth: true});
        }
        let ahead = 1;
        while (out.length % 7 !== 0) {
            const day = first.add(total - 1 + ahead, "day");
            ahead += 1;
            out.push({date: day.format("YYYY-MM-DD"), inMonth: false});
        }
        return out;
    }, [cursor]);

    const monthLabel = dayjs.utc(iso(cursor.y, cursor.m, 1)).format("MMMM");

    return (
        <section className="cal-view" aria-label={`${monthLabel} ${cursor.y}`} data-od-id="organizer-calendar-view">
            <header className="cal-view-head">
                <h3 className="cal-view-title">
                    {monthLabel} <span>{cursor.y}</span>
                </h3>
                <div className="cal-view-nav">
                    <button
                        type="button"
                        className="cal-view-navbtn"
                        aria-label={t`Previous month`}
                        onClick={() => setCursor((current) => step(current, -1))}
                    >
                        <IconChevronLeft size={16}/>
                    </button>
                    <button
                        type="button"
                        className="cal-view-today"
                        onClick={() => {
                            const now = dayjs.utc(today);
                            setCursor({y: now.year(), m: now.month()});
                        }}
                    >
                        {t`Today`}
                    </button>
                    <button
                        type="button"
                        className="cal-view-navbtn"
                        aria-label={t`Next month`}
                        onClick={() => setCursor((current) => step(current, 1))}
                    >
                        <IconChevronRight size={16}/>
                    </button>
                </div>
            </header>

            <div className="cal-view-weekdays" aria-hidden="true">
                {WEEKDAYS.map((weekday) => <span key={weekday}>{weekday}</span>)}
            </div>

            <div className="cal-view-grid">
                {cells.map((cell) => {
                    const list = byDate.get(cell.date) ?? [];
                    const parts = roomDateParts(cell.date);
                    const isToday = cell.date === today;
                    const isSelected = selected === cell.date;

                    const body = (
                        <>
                            <span className="cal-view-num">{parts.day}</span>
                            <span className="cal-view-chips">
                                {list.slice(0, MAX_CHIPS).map((event) => (
                                    <span className="cal-view-chip" key={event.key}>
                                        <span
                                            className="cal-view-dot"
                                            style={{background: roomCategoryColour(categories, event.category)}}
                                            aria-hidden="true"
                                        />
                                        <span className="t">{event.start}</span>
                                        <span className="n">{event.title}</span>
                                    </span>
                                ))}
                                {list.length > MAX_CHIPS ? (
                                    <span className="cal-view-more">+{list.length - MAX_CHIPS} {t`more`}</span>
                                ) : null}
                            </span>
                        </>
                    );

                    const shell = `cal-view-cell${cell.inMonth ? "" : " is-out"}${isToday ? " is-today" : ""}${isSelected ? " is-selected" : ""}`;

                    if (list.length === 0) {
                        return (
                            <div key={cell.date} className={shell} data-od-id={`cal-cell-${cell.date}`}>
                                {body}
                            </div>
                        );
                    }

                    return (
                        <button
                            key={cell.date}
                            type="button"
                            className={`${shell} has-events`}
                            onClick={() => onSelectDay(isSelected ? null : cell.date)}
                            aria-pressed={isSelected}
                            aria-label={`${list.length} ${list.length === 1 ? t`event` : t`events`} — ${parts.full}`}
                            data-od-id={`cal-cell-${cell.date}`}
                        >
                            {body}
                        </button>
                    );
                })}
            </div>
        </section>
    );
};
