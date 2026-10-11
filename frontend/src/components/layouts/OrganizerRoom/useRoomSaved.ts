import {useCallback, useMemo} from "react";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {useGetMySavedEvents} from "../../../queries/useGetMySavedEvents.ts";
import {useSaveEvent} from "../../../mutations/useSaveEvent.ts";
import {useUnsaveEvent} from "../../../mutations/useUnsaveEvent.ts";

const LOGIN_PATH = "/auth/login";
const PREVIOUS_URL_KEY = "previous_url";

/**
 * Saved events, account-scoped through the real API — the platform's half of
 * monno's `useSaved`. A signed-in visitor's saves live on `user_saved_events`
 * and show up on the website's profile too; a signed-out click is handed to
 * sign-in with the page remembered, so saving needs an account here as well.
 *
 * Keys stay the surfaces' own: `event:{id}` from the event room, `{id}-single`
 * (or `{id}-{occurrenceId}`) from the room's calendar — both normalise to the
 * event id the API stores.
 */
const eventIdFromKey = (key: string): number | null => {
    const match = key.match(/^(?:event:)?(\d+)/);
    return match ? Number(match[1]) : null;
};

export const useRoomSaved = () => {
    const {data: me} = useGetMe();
    const signedIn = Boolean(me?.id);
    const {data, isPending} = useGetMySavedEvents(signedIn);
    const saveMutation = useSaveEvent();
    const unsaveMutation = useUnsaveEvent();

    const savedIds = useMemo(
        () => new Set((data ?? []).map((item) => Number(item.event_id))),
        [data],
    );

    const toggle = useCallback((key: string) => {
        const eventId = eventIdFromKey(key);
        if (eventId === null) return;

        if (!signedIn) {
            // Saved events need an account: remember where they were and hand over.
            window.localStorage?.setItem(PREVIOUS_URL_KEY, window.location.href);
            window.location.href = LOGIN_PATH;
            return;
        }

        if (savedIds.has(eventId)) {
            unsaveMutation.mutate(eventId);
            return;
        }
        saveMutation.mutate(eventId);
    }, [signedIn, savedIds, saveMutation, unsaveMutation]);

    const isSaved = useCallback(
        (key: string) => {
            const eventId = eventIdFromKey(key);
            return eventId !== null && savedIds.has(eventId);
        },
        [savedIds],
    );

    return {
        ready: signedIn ? !isPending : true,
        saved: [...savedIds].map((id) => `event:${id}`),
        isSaved,
        toggle,
    };
};
