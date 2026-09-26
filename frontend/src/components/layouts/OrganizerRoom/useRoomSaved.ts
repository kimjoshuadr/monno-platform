import {useCallback, useEffect, useState} from "react";

const STORAGE_KEY = "room.saved.v1";

/**
 * Saved events, kept per browser — the port of monno's `useSaved`.
 *
 * The platform has no saved-events API, so this stays client-side exactly like the
 * website's, and the server never learns about it.
 */
export const useRoomSaved = () => {
    const [saved, setSaved] = useState<string[]>([]);
    const [ready, setReady] = useState(false);

    useEffect(() => {
        try {
            const raw = window.localStorage?.getItem(STORAGE_KEY);
            setSaved(raw ? (JSON.parse(raw) as string[]) : []);
        } catch {
            setSaved([]);
        }
        setReady(true);
    }, []);

    const toggle = useCallback((key: string) => {
        setSaved((current) => {
            const next = current.includes(key) ? current.filter((item) => item !== key) : [...current, key];
            try {
                window.localStorage?.setItem(STORAGE_KEY, JSON.stringify(next));
            } catch {
                // Storage unavailable (private mode) — the toggle still works for this session.
            }
            return next;
        });
    }, []);

    const isSaved = useCallback((key: string) => saved.includes(key), [saved]);

    return {ready, saved, isSaved, toggle};
};
