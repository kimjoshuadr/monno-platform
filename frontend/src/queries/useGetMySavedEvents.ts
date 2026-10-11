import {useQuery} from "@tanstack/react-query";
import {MySavedEvent, savedEventsClient} from "../api/savedEvent.client.ts";

export const GET_MY_SAVED_EVENTS_QUERY_KEY = "getMySavedEvents";

/**
 * The events this visitor saved. Only fetched when signed in — the endpoint is
 * account-scoped and 401s for anonymous visitors.
 */
export const useGetMySavedEvents = (enabled = true) =>
    useQuery<MySavedEvent[]>({
        queryKey: [GET_MY_SAVED_EVENTS_QUERY_KEY],
        queryFn: async () => {
            const response = await savedEventsClient.all();
            return response.data;
        },
        enabled,
        retry: false,
        refetchOnWindowFocus: false,
    });
