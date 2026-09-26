/* eslint-disable lingui/no-unlocalized-strings -- identifiers, format strings and ICS
   protocol tokens only; every user-facing string goes through `t`. */
import {RoomEvent} from "./roomData.ts";

/**
 * A combined `.ics` for a room's dates — the port of monno's room export, but one VEVENT
 * per date (a recurring platform event contributes one per occurrence).
 */
const escapeIcsText = (value: string): string =>
    (value ?? "")
        .replace(/\\/g, "\\\\")
        .replace(/;/g, "\\;")
        .replace(/,/g, "\\,")
        .replace(/\r\n|\r|\n/g, "\\n");

const formatIcsDate = (iso: string): string =>
    new Date(iso).toISOString().replace(/[-:]/g, "").replace(/\.\d{3}/, "");

export const buildRoomIcs = (events: RoomEvent[]): string => {
    const stamp = formatIcsDate(new Date().toISOString());

    const lines = events
        .filter((event) => Boolean(event.startsAt))
        .map((event) => {
            const start = formatIcsDate(event.startsAt);
            const end = formatIcsDate(
                new Date(new Date(event.startsAt).getTime() + 2 * 60 * 60 * 1000).toISOString(),
            );
            const location = [event.venue, event.city].filter(Boolean).join(", ");

            return [
                "BEGIN:VEVENT",
                `UID:${escapeIcsText(event.key)}@monno`,
                `DTSTAMP:${stamp}`,
                `DTSTART:${start}`,
                `DTEND:${end}`,
                `SUMMARY:${escapeIcsText(event.title)}`,
                location ? `LOCATION:${escapeIcsText(location)}` : "",
                "END:VEVENT",
            ].filter(Boolean);
        })
        .flat();

    return [
        "BEGIN:VCALENDAR",
        "VERSION:2.0",
        "PRODID:-//monno//organizer room//EN",
        "CALSCALE:GREGORIAN",
        "METHOD:PUBLISH",
        ...lines,
        "END:VCALENDAR",
    ].join("\r\n");
};

export const downloadRoomIcs = (filename: string, events: RoomEvent[]): void => {
    const blob = new Blob([buildRoomIcs(events)], {type: "text/calendar;charset=utf-8"});
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");

    anchor.href = url;
    anchor.download = filename.endsWith(".ics") ? filename : `${filename}.ics`;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
};
