import {useQuery} from "@tanstack/react-query";
import {eventsClient} from "../api/event.client.ts";
import {QueryFilters} from "../types.ts";

export const GET_EVENTS_QUERY_KEY = 'getEvents';

/**
 * `options.enabled` exists for the same reason as in useGetOrganizers: while an
 * organizer's email is unconfirmed the endpoint 403s, and the verify screens
 * need to defer the query rather than run it into the lock.
 */
export const useGetEvents = (pagination: QueryFilters, options?: {enabled?: boolean}) => {
    return useQuery({
        queryKey: [GET_EVENTS_QUERY_KEY, pagination],

        queryFn: async () => {
            return await eventsClient.all(pagination);
        },

        enabled: options?.enabled,
    });
};
