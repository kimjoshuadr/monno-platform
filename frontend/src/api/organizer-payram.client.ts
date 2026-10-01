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
};
