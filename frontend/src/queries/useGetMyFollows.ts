import {useQuery} from "@tanstack/react-query";
import {followsClient, MyFollow} from "../api/follow.client.ts";

export const GET_MY_FOLLOWS_QUERY_KEY = "getMyFollows";

/**
 * The organizers this visitor follows. Only fetched when signed in — the endpoint is
 * account-scoped and 401s for anonymous visitors.
 */
export const useGetMyFollows = (enabled = true) =>
    useQuery<MyFollow[]>({
        queryKey: [GET_MY_FOLLOWS_QUERY_KEY],
        queryFn: async () => {
            const response = await followsClient.all();
            return response.data;
        },
        enabled,
        retry: false,
        refetchOnWindowFocus: false,
    });
