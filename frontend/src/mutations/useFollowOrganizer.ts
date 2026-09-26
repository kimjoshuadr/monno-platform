import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {followsClient} from "../api/follow.client.ts";
import {GET_MY_FOLLOWS_QUERY_KEY} from "../queries/useGetMyFollows.ts";

/** Subscribe to an organizer's room (monno's "Subscribe", backed by the real API). */
export const useFollowOrganizer = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (organizerId: IdParam) => followsClient.create(organizerId),
        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: [GET_MY_FOLLOWS_QUERY_KEY]});
        },
    });
};
