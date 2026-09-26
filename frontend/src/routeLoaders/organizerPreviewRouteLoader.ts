/* eslint-disable lingui/no-unlocalized-strings -- identifiers, format strings and ICS
   protocol tokens only; every user-facing string goes through `t`. */
import {LoaderFunctionArgs} from "react-router";
import {getOrganizerPublicQuery} from "../queries/useGetOrganizerPublic.ts";
import {getQueryClient} from "../utilites/ssrQueryClient.ts";
import {loadOrganizerRoom} from "./publicOrganizerRouteLoader.ts";

/**
 * Loader for the organizer preview page - does NOT redirect based on slug
 * This is used by the iframe preview in the homepage designer
 */
export const organizerPreviewRouteLoader = async ({params}: LoaderFunctionArgs) => {
    const {organizerId} = params;

    if (!organizerId) {
        throw new Error('Organizer ID is required');
    }

    try {
        // Same room data as the public page, so the preview and the live page agree.
        await getQueryClient().fetchQuery(getOrganizerPublicQuery(organizerId));
        return await loadOrganizerRoom(organizerId);
    } catch (error: any) {
        if (error?.response?.status === 404) {
            return {organizer: null, upcoming: [], past: [], totals: {upcoming: 0, past: 0}, isPastEvents: false};
        }
        throw error;
    }
}
