/* eslint-disable lingui/no-unlocalized-strings -- identifiers, format strings and ICS
   protocol tokens only; every user-facing string goes through `t`. */
import {LoaderFunctionArgs, redirect} from "react-router";
import {getQueryClient} from "../utilites/ssrQueryClient.ts";
import {getOrganizerPublicQuery} from "../queries/useGetOrganizerPublic.ts";
import {getOrganizerPublicEventsQuery} from "../queries/useGetOrganizerEventsPublic.ts";
import {Event, Organizer} from "../types.ts";

/** What the organizer room needs: the profile plus both sides of its calendar. */
export interface OrganizerRoomLoaderData {
    organizer: Organizer | null;
    upcoming: Event[];
    past: Event[];
    totals: { upcoming: number; past: number };
    isPastEvents: boolean;
}

/** Both event sets are fetched up front — the room's When control needs both counts. */
export const loadOrganizerRoom = async (organizerId: string): Promise<OrganizerRoomLoaderData> => {
    const queryClient = getQueryClient();

    const organizer = await queryClient.fetchQuery(getOrganizerPublicQuery(organizerId));

    const [upcoming, past] = await Promise.all([
        queryClient.fetchQuery(getOrganizerPublicEventsQuery(organizerId, {
            pageNumber: 1,
            perPage: 100,
            sortBy: 'start_date',
            sortDirection: 'asc',
            additionalParams: {eventsStatus: 'upcoming'},
            filterFields: {},
        })),
        queryClient.fetchQuery(getOrganizerPublicEventsQuery(organizerId, {
            pageNumber: 1,
            perPage: 100,
            sortBy: 'start_date',
            sortDirection: 'desc',
            additionalParams: {eventsStatus: 'ended'},
            filterFields: {},
        })),
    ]);

    return {
        organizer,
        upcoming: upcoming?.data ?? [],
        past: past?.data ?? [],
        totals: {
            upcoming: upcoming?.meta?.total ?? upcoming?.data?.length ?? 0,
            past: past?.meta?.total ?? past?.data?.length ?? 0,
        },
        isPastEvents: false,
    };
};

export const publicOrganizerRouteLoader = async ({params, request}: LoaderFunctionArgs) => {
    const {organizerId, organizerSlug} = params;
    const url = new URL(request.url);
    const queryParams = new URLSearchParams(url.search);
    const isPastEvents = url.pathname.endsWith('/past-events');

    if (!organizerId) {
        throw new Error('Organizer ID is required');
    }

    try {
        const organizer = await getQueryClient().fetchQuery(getOrganizerPublicQuery(organizerId));

        if (organizer && organizer.slug && organizerSlug !== organizer.slug) {
            const searchString = queryParams.toString();
            const pathSuffix = isPastEvents ? '/past-events' : '';
            throw redirect(
                `/events/${organizer.id}/${organizer.slug}${pathSuffix}${searchString ? `?${searchString}` : ''}`
            );
        }

        const room = await loadOrganizerRoom(organizerId);

        return {...room, isPastEvents};
    } catch (error: any) {
        // Re-throw redirect responses so React Router can handle them
        if (error instanceof Response) {
            throw error;
        }

        if (error?.response?.status === 404) {
            return {organizer: null, upcoming: [], past: [], totals: {upcoming: 0, past: 0}, isPastEvents};
        }
        throw error;
    }
};
