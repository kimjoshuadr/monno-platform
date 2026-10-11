import {api} from "./client";
import {GenericDataResponse, GenericPaginatedResponse, IdParam} from "../types";

/**
 * An event a signed-in visitor saved for later ("Save for later" in the room).
 * The API is account-scoped: anonymous visitors get a 401, and the room hands
 * them to sign-in instead of saving locally.
 */
export interface MySavedEvent {
    event_id: number;
    saved_at?: string | null;
    title?: string;
    short_id?: string | null;
    location?: string | null;
    category?: string | null;
    starts_at?: string | null;
}

export const savedEventsClient = {
    all: async () => {
        const response = await api.get<GenericPaginatedResponse<MySavedEvent>>('me/saved-events');
        return response.data;
    },

    create: async (eventId: IdParam) => {
        const response = await api.post<GenericDataResponse<MySavedEvent>>('me/saved-events', {
            event_id: eventId,
        });
        return response.data;
    },

    remove: async (eventId: IdParam) => {
        await api.delete(`me/saved-events/${eventId}`);
    },
};
