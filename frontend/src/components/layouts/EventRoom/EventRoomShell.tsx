/* eslint-disable lingui/no-unlocalized-strings -- image-type keys, date/format strings and
   analytics names only; every user-facing string goes through `t`. */
import {useEffect, ReactNode} from "react";
import {Event} from "../../../types.ts";
import {EventDocumentHead} from "../../common/EventDocumentHead";
import {StatusToggle} from "../../common/StatusToggle";
import {computeThemeVariables, validateThemeSettings} from "../../../utilites/themeUtils.ts";
import {ensureHomepageFontLoaded} from "../../../utilites/fontLoader.ts";
import {useOrganizerTrackingPixels} from "../../../hooks/useOrganizerTrackingPixels";
import {hasActivePixels, trackPixelEvent} from "../../../utilites/trackingPixels";
import {removeTransparency} from "../../../utilites/colorHelper.ts";
import {EventRoom} from "./index.tsx";
import {EventRoomChromeFooter} from "./EventRoomChrome.tsx";

/**
 * Everything the event page owns besides its layout: the theme variables the ticket widget
 * reads, the document head, the homepage font, the organizer's tracking pixels, the publish
 * banner and the footer. Kept out of `EventRoom` so the room stays presentational.
 */
export const EventRoomShell = ({
    event,
    promoCode,
    promoCodeValid,
    initialOccurrenceId,
}: {
    event: Event;
    promoCode?: string;
    promoCodeValid?: boolean;
    initialOccurrenceId?: number | null;
}) => {
    const themeSettings = validateThemeSettings(event.settings?.homepage_theme_settings);
    const cssVars = computeThemeVariables(themeSettings);
    const organizer = event.organizer;

    const {pixelsReady} = useOrganizerTrackingPixels(organizer?.settings?.tracking_pixels);

    useEffect(() => {
        ensureHomepageFontLoaded(themeSettings.font_family);
    }, [themeSettings.font_family]);

    useEffect(() => {
        if (pixelsReady && hasActivePixels()) {
            trackPixelEvent({
                eventName: 'ViewContent',
                contentName: event.title,
                contentId: event.id,
            });
        }
    }, [event.id, event.title, pixelsReady]);

    // The variables the ticket widget styles itself from.
    const themeStyles: Record<string, string> = {
        '--event-bg-color': themeSettings.background,
        '--event-content-bg-color': cssVars['--theme-surface'],
        '--event-primary-color': themeSettings.accent,
        '--event-primary-text-color': cssVars['--theme-text-primary'],
        '--event-secondary-color': cssVars['--theme-text-secondary'],
        '--event-secondary-text-color': cssVars['--theme-text-tertiary'],
        '--event-accent-contrast': cssVars['--theme-accent-contrast'],
        '--event-accent-soft': cssVars['--theme-accent-soft'],
        '--event-accent-muted': cssVars['--theme-accent-muted'],
        '--event-border-color': cssVars['--theme-border'],
        '--theme-font-family': cssVars['--theme-font-family'],
        fontFamily: cssVars['--theme-font-family'],
    };

    const banner: ReactNode = event.id && event.organizer_id && event.status ? (
        <div className="room-banner">
            <StatusToggle
                entityType="event"
                entityId={event.id}
                currentStatus={event.status as 'DRAFT' | 'LIVE' | 'PENDING_MANUAL_REVIEW'}
                entityName={event.title}
                onSuccess={() => window.location.reload()}
            />
        </div>
    ) : null;

    return (
        <>
            <EventDocumentHead event={event}/>
            <style>{`
                body, .ssr-loader {
                    background-color: ${removeTransparency(themeSettings.background)} !important;
                }
            `}</style>

            <EventRoom
                event={event}
                organizer={organizer ?? null}
                banner={banner}
                footer={<EventRoomChromeFooter/>}
                mode={themeSettings.mode === 'dark' ? 'dark' : 'light'}
                theme={themeSettings}
                style={themeStyles as React.CSSProperties}
                promoCode={promoCode}
                promoCodeValid={promoCodeValid}
                initialOccurrenceId={initialOccurrenceId}
            />
        </>
    );
};

export default EventRoomShell;
