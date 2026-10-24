/* eslint-disable lingui/no-unlocalized-strings -- message types and query keys only */
import {getEmbedParentUrl, getParentOrigin} from "./iframeResize.ts";

/** The style params an embed was loaded with, passed on to the checkout it opens. */
const STYLE_KEYS = ['Theme', 'Accent', 'Radius', 'Locale'];

/**
 * Open the ticket picker for an event. Inside a host page running the monno
 * script it opens in the on-page modal; anywhere else (a direct visit, the
 * dashboard preview) the event's website page opens in a new tab.
 */
export const openEmbedCheckout = (eventId: number | string, fallbackUrl?: string) => {
    const params = new URLSearchParams(window.location.search);
    const passOn = STYLE_KEYS
        .filter((key) => params.get(key))
        .map((key) => `${key}=${encodeURIComponent(params.get(key) as string)}`);

    if (window.parent !== window && getEmbedParentUrl()) {
        window.parent.postMessage(
            {type: 'monno:open-checkout', eventId: String(eventId), params: ['embed=modal', ...passOn]},
            getParentOrigin() || '*'
        );
        return;
    }

    if (fallbackUrl) window.open(fallbackUrl, '_blank', 'noopener,noreferrer');
};
