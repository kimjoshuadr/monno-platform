import {api} from "./client.ts";
import {IdParam, OrganizerPayRamAccountResponse} from "../types.ts";

export const organizerPayRamClient = {
    /** Report only — never creates anything. */
    getAccount: async (organizerId: IdParam) => {
        const response = await api.get<OrganizerPayRamAccountResponse>(
            `organizers/${organizerId}/payram/account`,
        );
        return response.data;
    },

    /** Idempotent: provisions on first call, reports afterwards. */
    ensureAccount: async (organizerId: IdParam) => {
        const response = await api.post<OrganizerPayRamAccountResponse>(
            `organizers/${organizerId}/payram/account`,
        );
        return response.data;
    },

    /** Save the crypto setup wizard's wallet and currency choices */
    setupCryptoConnect: async (
        organizerId: IdParam,
        data: {
            wallet_address: string;
            currencies?: string[];
            tron_wallet_address?: string;
            btc_wallet_address?: string;
        }
    ) => {
        const response = await api.post<OrganizerPayRamAccountResponse>(
            `organizers/${organizerId}/payram/setup`,
            data,
        );
        return response.data;
    },

    /** Mint a one-time code that opens the PayRam console already signed in. */
    createSsoToken: async (organizerId: IdParam) => {
        const response = await api.post<{
            code: string;
            dashboard_url: string;
            exchange_url: string;
        }>(`organizers/${organizerId}/payram/sso-token`);
        return response.data;
    },
};
