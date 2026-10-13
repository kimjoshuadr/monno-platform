import {useMutation, useQueryClient} from "@tanstack/react-query";
import {adminClient} from "../api/admin.client";
import {IdParam} from "../types";
import {GET_ALL_EVENTS_QUERY_KEY} from "../queries/useGetAllAdminEvents";

/**
 * Tick (or untick) an event as featured, from the superadmin events table.
 * Featuring drives the hero floats and the featured rail on the public site —
 * organizers cannot set it, so this is where the flag lives.
 */
export const useSetEventFeatured = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, featured}: {eventId: IdParam, featured: boolean}) =>
            adminClient.setEventFeatured(eventId, featured),
        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: [GET_ALL_EVENTS_QUERY_KEY]});
        },
    });
};
