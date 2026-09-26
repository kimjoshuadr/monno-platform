/* eslint-disable lingui/no-unlocalized-strings -- identifiers, format strings and ICS
   protocol tokens only; every user-facing string goes through `t`. */
/**
 * View models + adapters for the organizer room.
 *
 * The room's design comes from monno, whose pages read a flat, date-centric event model
 * (one entry per date, pre-formatted strings). The platform stores richer events with
 * occurrences, products and timezones, so this module flattens them into the shapes the
 * ported components expect.
 */

import dayjs from 'dayjs';
import utc from 'dayjs/plugin/utc';
import timezone from 'dayjs/plugin/timezone';
import {Event, EventOccurrence, IdParam, Organizer, OrganizerSettings} from '../types.ts';
import {formatCurrency} from './currency.ts';
import {getProductsFromEvent} from './helpers.ts';
import {socialMediaConfig} from '../constants/socialMediaConfig.ts';
import {eventHomepagePath} from './urlHelper.ts';

dayjs.extend(utc);
dayjs.extend(timezone);

export interface RoomEvent {
    /** Unique per row — a recurring event contributes one row per occurrence. */
    key: string;
    link: string;
    title: string;
    /** Local-to-the-event date, `YYYY-MM-DD`, used for grouping and the calendar. */
    date: string;
    start: string;
    sortTime: string;
    startsAt: string;
    category: string;
    city: string;
    venue: string;
    price: number;
    currency: string;
    soldOut: boolean;
    spotsLeft: number | null;
    image?: string;
    timezone: string;
}

export interface RoomSocial {
    platform: string;
    href: string;
    label: string;
}

export interface RoomHost {
    name: string;
    handle: string;
    bio: string;
    location: string;
    cover?: string;
    avatar?: string;
    socials: RoomSocial[];
}

export interface RoomDay {
    date: string;
    events: RoomEvent[];
}

export interface RoomDateParts {
    weekday: string;
    month: string;
    day: string;
    full: string;
    monthLong: string;
    year: number;
}

const SPECTRUM_HUE_START = 218;
const SPECTRUM_HUE_END = 33;

/**
 * Category → spectrum colour, the bright tier monno uses for its map pins and the
 * calendar's day chips. Categories are ordered as the platform serves them, so the hues
 * stay stable across both apps.
 */
export const roomCategoryColour = (categories: string[], category: string): string => {
    const index = Math.max(0, categories.indexOf(category));
    const ratio = categories.length <= 1 ? 0 : index / (categories.length - 1);
    const hue = SPECTRUM_HUE_START + (SPECTRUM_HUE_END - SPECTRUM_HUE_START) * ratio;
    return `oklch(0.75 0.14 ${hue.toFixed(1)})`;
};

/** The categories the room's colour ramp is built from (platform order). */
export const roomCategoryOrder = (events: RoomEvent[]): string[] =>
    [...new Set(events.map((event) => event.category).filter(Boolean))].sort();

export const roomDateParts = (date: string): RoomDateParts => {
    const parsed = dayjs.utc(date);
    return {
        weekday: parsed.format('ddd'),
        month: parsed.format('MMM'),
        day: parsed.format('D'),
        full: parsed.format('dddd, MMMM D, YYYY'),
        monthLong: parsed.format('MMMM'),
        year: parsed.year(),
    };
};

export const roomTodayKey = (): string => dayjs().format('YYYY-MM-DD');

/** Group rows by day; `order` controls whether the calendar reads forward or back. */
export const groupRoomDays = (events: RoomEvent[], order: 'forward' | 'back' = 'forward'): RoomDay[] => {
    const byDate = new Map<string, RoomEvent[]>();
    for (const event of events) {
        byDate.set(event.date, [...(byDate.get(event.date) ?? []), event]);
    }

    return [...byDate.entries()]
        .sort(([a], [b]) => (order === 'back' ? b.localeCompare(a) : a.localeCompare(b)))
        .map(([date, dayEvents]) => ({
            date,
            events: dayEvents.sort((a, b) => a.sortTime.localeCompare(b.sortTime)),
        }));
};

const imageOfType = (images: Event['images'], type: string): string | undefined =>
    images?.find((image) => image.type === type)?.url;

/** Lowest price across an event's products — `0` when anything is free. */
export const roomLowestPrice = (event: Event): number => {
    const products = getProductsFromEvent(event) ?? [];
    let lowest: number | null = null;

    for (const product of products) {
        const prices = product.prices?.length ? product.prices.map((price) => price.price ?? 0) : [product.price ?? 0];
        for (const price of prices) {
            if (lowest === null || price < lowest) {
                lowest = price;
            }
        }
    }

    return lowest ?? 0;
};

export const roomPriceLabel = (price: number, currency: string): string =>
    price === 0 ? 'Free' : formatCurrency(price, currency);

const roomSpotsLeft = (event: Event): number | null => {
    const products = getProductsFromEvent(event) ?? [];
    const quantities = products
        .map((product) => product.quantity_available)
        .filter((quantity): quantity is number => typeof quantity === "number");

    return quantities.length ? Math.min(...quantities) : null;
};

/**
 * Tickets sold and total capacity, from the products the room already has.
 *
 * Counts only — the public API never exposes who bought, so this is what a
 * "who's coming" line or a capacity bar can honestly be built from. Capacity is
 * `null` when no product reports remaining stock (the organizer can hide it).
 */
export const roomAttendance = (event: Event): { registered: number; capacity: number | null } => {
    let registered = 0;
    let remaining = 0;
    let hasRemaining = false;

    for (const product of getProductsFromEvent(event) ?? []) {
        if (product.is_addon_only) {
            continue;
        }

        for (const price of product.prices ?? []) {
            registered += Number(price.quantity_sold ?? 0);
            if (typeof price.quantity_remaining === 'number') {
                remaining += price.quantity_remaining;
                hasRemaining = true;
            }
        }
    }

    return {registered, capacity: hasRemaining ? registered + remaining : null};
};


const roomSoldOut = (event: Event): boolean =>
    Boolean(event.upcoming_occurrences_sold_out)
    || (getProductsFromEvent(event) ?? []).every((product) => product.is_sold_out);

const venueOf = (event: Event, occurrence?: EventOccurrence | null): { venue: string; city: string } => {
    const location = occurrence?.event_location?.location ?? event.event_location?.location;
    const address = location?.structured_address as Record<string, unknown> | undefined;
    const city = (address?.city as string) || (address?.state_or_region as string) || '';
    const venue = location?.name || (address?.venue_name as string) || (city ? '' : 'Online');

    return {venue: venue || '', city};
};

/**
 * Flatten an event (and each of its occurrences) into the room's date-centric rows.
 * Occurrences drive recurring events; single events contribute one row.
 */
export const toRoomEvents = (event: Event): RoomEvent[] => {
    const timezoneName = event.timezone || 'UTC';
    const category = (event.category as string) || '';
    const price = roomLowestPrice(event);
    const currency = event.currency || 'USD';
    const soldOut = roomSoldOut(event);
    const spotsLeft = roomSpotsLeft(event);
    const image = imageOfType(event.images, 'EVENT_COVER');
    const occurrences = (event.occurrences ?? []).filter((occurrence) => occurrence.status !== 'CANCELLED');

    const rows: RoomEvent[] = (occurrences.length ? occurrences : [null]).map((occurrence) => {
        const startsAt = occurrence?.start_date || event.start_date;
        const localStart = dayjs.utc(startsAt).tz(timezoneName);
        const {venue, city} = venueOf(event, occurrence);

        return {
            key: `${event.id}-${occurrence?.id ?? 'single'}`,
            link: eventHomepagePath(event),
            title: event.title || '',
            date: localStart.format('YYYY-MM-DD'),
            start: localStart.format('HH:mm'),
            sortTime: localStart.format('HH:mm'),
            startsAt: startsAt || '',
            category,
            city,
            venue,
            price,
            currency,
            soldOut: soldOut || Boolean(occurrence?.status === 'SOLD_OUT'),
            spotsLeft,
            image,
            timezone: timezoneName,
        };
    });

    return rows;
};

export const roomEventsFrom = (event: Event): RoomEvent[] => {
    const rows = toRoomEvents(event);
    return rows.filter((row) => Boolean(row.date));
};

export const toRoomHost = (
    organizer: Organizer,
    settings?: OrganizerSettings | null,
    organizationName?: string,
): RoomHost => {
    const handles = settings?.social_media_handles ?? {};
    const socials: RoomSocial[] = Object.entries(handles)
        .filter(([platform, handle]) => Boolean(handle) && platform in socialMediaConfig)
        .map(([platform, handle]) => {
            const config = socialMediaConfig[platform as keyof typeof socialMediaConfig];
            const value = String(handle);
            const href = /^https?:\/\//.test(value)
                ? value
                : `${config.baseUrl}${value.replace(/^@/, '')}`;

            return {platform, href, label: `${platform} profile`};
        });

    const address = organizer.location?.structured_address as Record<string, unknown> | undefined;
    const location = (address?.city as string)
        || (address?.state_or_region as string)
        || organizer.location?.name
        || '';

    return {
        name: organizationName || organizer.name || '',
        handle: organizer.slug || '',
        bio: organizer.description || '',
        location,
        cover: imageOfType(organizer.images, 'ORGANIZER_COVER'),
        avatar: imageOfType(organizer.images, 'ORGANIZER_LOGO'),
        socials,
    };
};

/** Theme (designer) values → the room's scoped design tokens. */
export const roomThemeStyle = (
    theme?: {accent?: string; background?: string; mode?: 'light' | 'dark'; font_family?: string} | null,
): Record<string, string> => {
    const style: Record<string, string> = {};
    if (theme?.accent) style['--room-accent'] = theme.accent;
    if (theme?.background) style['--room-background'] = theme.background;
    if (theme?.font_family) style['--room-font'] = `'${theme.font_family}', system-ui, sans-serif`;
    return style;
};

export const roomEventKey = (eventId: IdParam, occurrence?: EventOccurrence | null): string =>
    `${eventId}-${occurrence?.id ?? 'single'}`;
