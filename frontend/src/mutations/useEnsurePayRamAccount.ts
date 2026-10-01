import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {organizerPayRamClient} from "../api/organizer-payram.client.ts";
import {GET_PAYRAM_ACCOUNT_QUERY_KEY} from "../queries/useGetPayRamAccount.ts";

/**
 * Provisions the organizer's PayRam merchant account on first use and reports
 * it afterwards. The response carries dashboard credentials exactly once — on
 * the call that created the account — so the result is cached, not refetched.
 */
export const useEnsurePayRamAccount = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({organizerId}: {organizerId: IdParam}) =>
            organizerPayRamClient.ensureAccount(organizerId),
        onSuccess: (data, variables) => {
            queryClient.setQueryData(
                [GET_PAYRAM_ACCOUNT_QUERY_KEY, variables.organizerId],
                data,
            );
        },
    });
};
