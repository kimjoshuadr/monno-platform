import {useMemo, useState} from "react";
import {t} from "@lingui/macro";
import {IconChevronLeft, IconChevronRight} from "@tabler/icons-react";
import dayjs from "dayjs";
import {RoomEvent, roomTodayKey} from "../../../utilites/roomData.ts";

const WEEKDAYS = Array.from({length: 7}, (_, index) => dayjs().day(index).format("ddd"));

type Cursor = { y: number; m: number };

const step = ({y, m}: Cursor, delta: number): Cursor => {
    const next = dayjs.utc(`${y}-${String(m + 1).padStart(2, "0")}-01`).add(delta, "month");
    return {y: next.year(), m: next.month()};
};

const iso = (y: number, m: number, d: number) =>
    `${y}-${String(m + 1).padStart(2, "0")}-${String(d).padStart(2, "0")}`;

interface MonthPickerProps {
    events: RoomEvent[];
    selected?: string | null;
    onSelect?: (date: string | null) => void;
}

/**
 * monno's compact month grid: square cells, a dot per day that has something on, the
 * chosen day filled in ink and today keeping its own ring. Picking a dated cell filters
 * the timeline; picking it again clears that.
 */
export const RoomMonthPicker = ({events, selected, onSelect}: MonthPickerProps) => {
    const today = roomTodayKey();

    const counts = useMemo(() => {
        const map = new Map<string, number>();
        for (const event of events) {
            map.set(event.date, (map.get(event.date) ?? 0) + 1);
        }
        return map;
    }, [events]);

    const [cursor, setCursor] = useState<Cursor>(() => {
        const first = events[0]?.date;
        const start = first ? dayjs.utc(first) : dayjs.utc(today);
        return {y: start.year(), m: start.month()};
    });

    const cells = useMemo(() => {
        const first = dayjs.utc(`${iso(cursor.y, cursor.m, 1)}`);
        const lead = first.day();
        const total = first.daysInMonth();
        const out: (string | null)[] = Array.from({length: lead}, () => null);
        for (let day = 1; day <= total; day += 1) {
            out.push(iso(cursor.y, cursor.m, day));
        }
        while (out.length % 7 !== 0) {
            out.push(null);
        }
        return out;
    }, [cursor]);

    return (
        <section className="cal-month" aria-label={t`Month calendar`} data-od-id="organizer-month">
            <header className="cal-month-head">
                <p className="cal-month-title">
                    {dayjs.utc(`${iso(cursor.y, cursor.m, 1)}`).format("MMMM")} <span>{cursor.y}</span>
                </p>
                <div className="cal-month-nav">
                    <button
                        type="button"
                        className="cal-month-navbtn"
                        aria-label={t`Previous month`}
                        onClick={() => setCursor((current) => step(current, -1))}
                    >
                        <IconChevronLeft size={16}/>
                    </button>
                    <button
                        type="button"
                        className="cal-month-today"
                        onClick={() => {
                            const now = dayjs.utc(today);
                            setCursor({y: now.year(), m: now.month()});
                        }}
                    >
                        {t`Today`}
                    </button>
                    <button
                        type="button"
                        className="cal-month-navbtn"
                        aria-label={t`Next month`}
                        onClick={() => setCursor((current) => step(current, 1))}
                    >
                        <IconChevronRight size={16}/>
                    </button>
                </div>
            </header>

            <div className="cal-month-weekdays" aria-hidden="true">
                {WEEKDAYS.map((weekday) => <span key={weekday}>{weekday}</span>)}
            </div>

            <div className="cal-month-days">
                {cells.map((date, index) => {
                    if (!date) {
                        return <span key={`blank-${index}`} className="cal-month-cell is-blank" aria-hidden="true"/>;
                    }

                    const count = counts.get(date) ?? 0;
                    const day = Number(date.slice(8));
                    const isToday = date === today;
                    const isSelected = selected === date;

                    if (count === 0) {
                        return (
                            <span key={date} className={`cal-month-cell${isToday ? " is-today" : ""}`}>
                                {day}
                            </span>
                        );
                    }

                    return (
                        <button
                            key={date}
                            type="button"
                            className={`cal-month-cell has-events${isToday ? " is-today" : ""}${isSelected ? " is-selected" : ""}`}
                            onClick={() => onSelect?.(isSelected ? null : date)}
                            aria-pressed={isSelected}
                            aria-label={isSelected
                                ? `${t`Clear day filter`}: ${date}`
                                : `${count} ${count === 1 ? t`event` : t`events`} — ${date}`}
                            data-od-id={`month-day-${date}`}
                        >
                            <span className="cal-month-num">{day}</span>
                            <span className="cal-month-dots" aria-hidden="true">
                                {Array.from({length: Math.min(count, 3)}).map((_, dot) => <i key={dot}/>)}
                            </span>
                        </button>
                    );
                })}
            </div>
        </section>
    );
};
