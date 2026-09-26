import {api} from "./client";
import {GenericDataResponse, GenericPaginatedResponse, IdParam} from "../types";

/**
 * The organizer a signed-in visitor follows ("Subscribe" in the room).
 * The API is account-scoped: anonymous visitors get a 401, and the room hides the
 * button in that case.
 */
export interface MyFollow {
    organizer_id: number;
    name: string;
    description?: string | null;
    website?: string | null;
    followed_at?: string | null;
}

export const followsClient = {
    all: async () => {
        const response = await api.get<GenericPaginatedResponse<MyFollow>>('me/follows');
        return response.data;
    },

    create: async (organizerId: IdParam) => {
        const response = await api.post<GenericDataResponse<MyFollow>>('me/follows', {
            organizer_id: organizerId,
        });
        return response.data;
    },

    remove: async (organizerId: IdParam) => {
        await api.delete(`me/follows/${organizerId}`);
    },
};
