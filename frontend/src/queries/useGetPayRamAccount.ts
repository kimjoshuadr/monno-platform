import {useQuery} from "@tanstack/react-query";
import {organizerPayRamClient} from "../api/organizer-payram.client.ts";
import {IdParam} from "../types.ts";

export const GET_PAYRAM_ACCOUNT_QUERY_KEY = 'getPayRamAccount';

/**
 * Reports the organizer's PayRam merchant account without creating one, so
 * merely opening settings has no side effects on the gateway.
 */
export const useGetPayRamAccount = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_PAYRAM_ACCOUNT_QUERY_KEY, organizerId],
        queryFn: async () => await organizerPayRamClient.getAccount(organizerId),
        retry: false,
        staleTime: 30_000,
    });
}
