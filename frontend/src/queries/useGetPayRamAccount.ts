import {useQuery} from "@tanstack/react-query";
import {organizerPayRamClient} from "../api/organizer-payram.client.ts";
import {IdParam} from "../types.ts";

export const GET_PAYRAM_ACCOUNT_QUERY_KEY = 'getPayRamAccount';

/**
 * Reports the organizer's PayRam merchant account without creating one, so
 * merely opening settings has no side effects on the gateway.
 *
 * While a wallet is still unconfirmed this re-checks every 20 seconds: the
 * organizer's next step happens in the PayRam console (in another tab), and
 * PayRam only reports `coldWalletConfigured` once they are done. Without this
 * the card sat stale and they had to know to reload — the one gesture we
 * should never require. Once confirmed there is nothing more to watch for, so
 * the poll stops.
 *
 * The account endpoint reads the gateway through a 20s cache, so this costs
 * one upstream call per window rather than one per tab.
 */
export const useGetPayRamAccount = (organizerId: IdParam) => {
    return useQuery({
        queryKey: [GET_PAYRAM_ACCOUNT_QUERY_KEY, organizerId],
        queryFn: async () => await organizerPayRamClient.getAccount(organizerId),
        retry: false,
        staleTime: 15_000,
        refetchInterval: (query) => {
            const data = query.state.data;
            if (!data) return false;

            // Nothing pending: stop polling.
            if (data.status === 'READY'
                && data.gateway?.available === true
                && data.gateway.cold_wallet_configured === true) {
                return false;
            }

            return 20_000;
        },
    });
}
