import {getConfig} from "./config.ts";

/**
 * Links from the platform back to the monno website — the public, canonical
 * home of every event and room. The dashboard, the event room chrome and the
 * embeds all build their website links here, from `VITE_MONNO_SITE_URL`.
 */
const SITE = getConfig('VITE_MONNO_SITE_URL', 'http://localhost:3000') ?? 'http://localhost:3000';

/** The website root, without a trailing slash. */
export const monnoSiteUrl = (path = ''): string => `${SITE.replace(/\/+$/, '')}${path}`;

/**
 * The website's page for a platform event. The id is the stable key: a
 * platform slug is derived from the title and two events can share one.
 */
export const monnoEventUrl = (eventId?: number | string | null): string | undefined => {
    if (eventId === undefined || eventId === null || eventId === '') return undefined;
    return monnoSiteUrl(`/event/${eventId}/`);
};

/** The website's room for an organizer — it exists once they have a LIVE event. */
export const monnoOrganizerUrl = (organizerId?: number | string | null): string | undefined => {
    if (organizerId === undefined || organizerId === null || organizerId === '') return undefined;
    return monnoSiteUrl(`/o/${organizerId}/`);
};
