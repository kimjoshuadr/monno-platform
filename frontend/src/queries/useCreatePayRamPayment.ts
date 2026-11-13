import {useQuery} from "@tanstack/react-query";
import {orderClientPublic} from "../api/order.client.ts";
import {IdParam} from "../types.ts";

export const GET_INITIATE_PAYRAM_SESSION_PUBLIC_QUERY_KEY = 'getPayRamSessionPublic';

/**
 * Opens a hosted PayRam checkout session for the order, so the payment step can
 * show the USD equivalent (and fee) before the buyer is redirected.
 */
export const useCreatePayRamPayment = (eventId: IdParam, orderShortId: IdParam) => {
    return useQuery({
        queryKey: [GET_INITIATE_PAYRAM_SESSION_PUBLIC_QUERY_KEY, eventId, orderShortId],

        queryFn: async () => {
            return await orderClientPublic.createPayRamPayment(
                Number(eventId),
                String(orderShortId),
            );
        },

        retry: false,
        staleTime: 0,
        gcTime: 0,
    });
}
