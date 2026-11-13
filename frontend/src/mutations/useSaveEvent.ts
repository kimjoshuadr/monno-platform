import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {savedEventsClient} from "../api/savedEvent.client.ts";
import {GET_MY_SAVED_EVENTS_QUERY_KEY} from "../queries/useGetMySavedEvents.ts";

/** Save an event for later (monno's "Save for later", backed by the real API). */
export const useSaveEvent = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (eventId: IdParam) => savedEventsClient.create(eventId),
        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: [GET_MY_SAVED_EVENTS_QUERY_KEY]});
        },
    });
};
