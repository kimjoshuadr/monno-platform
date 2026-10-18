import {hydrateRoot} from "react-dom/client";
import {createBrowserRouter, matchRoutes, RouterProvider} from "react-router-dom";

import {router} from "./router";
import {App} from "./App";
import {queryClient} from "./utilites/queryClient";
import {installChunkReloadRecovery} from "./utilites/chunkReload";
import {dynamicActivateLocale, getClientLocale, getSupportedLocale,} from "./locales.ts";
import type {ThemeColors} from "./utilites/themeColors.ts";
import type {DehydratedState} from "@tanstack/react-query";

// Installed before anything is imported dynamically: a deploy replaces the
// hashed chunks an open tab is still pointing at, and the first stale route it
// reaches must recover by reloading rather than rendering the error page.
installChunkReloadRecovery();

declare global {
    interface Window {
        __REHYDRATED_STATE__?: DehydratedState;
        __THEME_COLORS__?: ThemeColors;
    }
}

const dehydratedState = window.__REHYDRATED_STATE__;

async function initClientApp() {
    const rawLocale = getClientLocale();
    const locale = getSupportedLocale(rawLocale);
    await dynamicActivateLocale(locale);
    const themeColors = window.__THEME_COLORS__
        ?? (await import("./utilites/themeColors.ts")).generateThemeColors();

    // Resolve lazy-loaded routes before hydration
    const matches = matchRoutes(router, window.location)?.filter((m) => m.route.lazy);
    if (matches && matches.length > 0) {
        await Promise.all(
            matches.map(async (m) => {
                const lazy = m.route.lazy;
                if (typeof lazy !== 'function') {
                    return;
                }
                const routeModule = await lazy();
                Object.assign(m.route, {...routeModule, lazy: undefined});
            })
        );
    }

    const browserRouter = createBrowserRouter(router);

    hydrateRoot(
        document.getElementById("app") as HTMLElement,
        <App queryClient={queryClient} locale={rawLocale} themeColors={themeColors} dehydratedState={dehydratedState}>
            <RouterProvider router={browserRouter}/>
        </App>
    );
}

initClientApp();
