import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {organizerPayRamClient} from "../api/organizer-payram.client.ts";
import {GET_PAYRAM_ACCOUNT_QUERY_KEY} from "../queries/useGetPayRamAccount.ts";

export const useSetupPayRamCryptoConnect = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({
            organizerId,
            data,
        }: {
            organizerId: IdParam;
            data: {
                wallet_address: string;
                currencies?: string[];
                tron_wallet_address?: string;
                btc_wallet_address?: string;
            };
        }) => organizerPayRamClient.setupCryptoConnect(organizerId, data),
        onSuccess: (data, variables) => {
            queryClient.setQueryData(
                [GET_PAYRAM_ACCOUNT_QUERY_KEY, variables.organizerId],
                data,
            );
            queryClient.invalidateQueries({
                queryKey: [GET_PAYRAM_ACCOUNT_QUERY_KEY, variables.organizerId],
            });
        },
    });
};
