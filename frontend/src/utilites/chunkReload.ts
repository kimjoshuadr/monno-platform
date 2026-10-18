/**
 * Stale-chunk recovery.
 *
 * The app is almost entirely lazy routes (88 of them). Asset filenames are
 * content-hashed, so every deploy replaces them: a tab that was already open
 * still holds the previous build's chunk map, and the next route it navigates to
 * requests a file that no longer exists. The request 404s, the route's `lazy()`
 * import rejects, and React Router renders the generic "Something went wrong"
 * page — which is what a stale tab looked like on staging after a deploy.
 *
 * Vite dispatches `vite:preloadError` for a failed dynamic import (the route
 * chunks included). Reloading once picks up the new `index.html`, and with it
 * the new chunk map, so the navigation succeeds. The cooldown is what keeps this
 * from becoming a reload loop when reloading genuinely cannot help.
 */

const RELOAD_AT_KEY = "monno.chunkReloadAt";

/** One automatic reload per window; a real deploy is never this close together. */
const RELOAD_COOLDOWN_MS = 5 * 60 * 1000;

export function installChunkReloadRecovery(): void {
    if (typeof window === "undefined") {
        return;
    }

    window.addEventListener("vite:preloadError", (event) => {
        // Tells Vite the failure is handled, so it does not rethrow it — the
        // reload below replaces the error page the throw would have rendered.
        if (typeof event.preventDefault === "function") {
            event.preventDefault();
        }

        try {
            const lastReloadAt = Number(window.sessionStorage.getItem(RELOAD_AT_KEY) ?? 0);
            if (Date.now() - lastReloadAt < RELOAD_COOLDOWN_MS) {
                return;
            }
            window.sessionStorage.setItem(RELOAD_AT_KEY, String(Date.now()));
        } catch {
            // Storage can be unavailable (private mode, blocked cookies). The
            // reload is still the right move; without storage only the cooldown
            // is lost, and the failure it guards against is a stale chunk that
            // reloading fixes.
        }

        window.location.reload();
    });
}
