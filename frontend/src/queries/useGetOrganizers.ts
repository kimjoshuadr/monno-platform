import {useQuery} from "@tanstack/react-query";
import {organizerClient} from "../api/organizer.client.ts";

export const GET_ORGANIZERS_QUERY_KEY = 'getOrganizers';

/**
 * `options.enabled` matters for an organizer whose email is not confirmed yet:
 * the endpoint returns 403 for them, so the verify screens must be able to hold
 * this query back instead of firing it into the lock.
 */
export const useGetOrganizers = (options?: {enabled?: boolean}) => {
    return useQuery({
        queryKey: [GET_ORGANIZERS_QUERY_KEY],

        queryFn: async () => {
            return await organizerClient.all();
        },

        enabled: options?.enabled,
    });
};
