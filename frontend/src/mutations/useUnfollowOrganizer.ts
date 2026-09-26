import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {followsClient} from "../api/follow.client.ts";
import {GET_MY_FOLLOWS_QUERY_KEY} from "../queries/useGetMyFollows.ts";

/** Unsubscribe from an organizer's room. */
export const useUnfollowOrganizer = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (organizerId: IdParam) => followsClient.remove(organizerId),
        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: [GET_MY_FOLLOWS_QUERY_KEY]});
        },
    });
};
