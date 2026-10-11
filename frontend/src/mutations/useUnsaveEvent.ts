import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {savedEventsClient} from "../api/savedEvent.client.ts";
import {GET_MY_SAVED_EVENTS_QUERY_KEY} from "../queries/useGetMySavedEvents.ts";

/** Drop an event from the saved list. */
export const useUnsaveEvent = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (eventId: IdParam) => savedEventsClient.remove(eventId),
        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: [GET_MY_SAVED_EVENTS_QUERY_KEY]});
        },
    });
};
