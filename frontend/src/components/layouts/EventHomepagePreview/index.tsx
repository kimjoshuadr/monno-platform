import {useEffect, useState} from "react";
import {useParams} from "react-router";
import {LoadingOverlay} from "@mantine/core";
import {Event, HomepageBlock, HomepageThemeSettings} from "../../../types.ts";
import {useGetEventPublic} from "../../../queries/useGetEventPublic.ts";
import {EventNotAvailable} from "../EventHomepage/EventNotAvailable";
import {EventRoomShell} from "../EventRoom/EventRoomShell.tsx";

interface PreviewSettings {
    homepage_theme_settings?: Partial<HomepageThemeSettings>;
    continue_button_text?: string;
    get_tickets_button_text?: string;
    homepage_blocks?: HomepageBlock[];
}

const EventHomepagePreview = () => {
    const {eventId} = useParams();
    const {data: event, isFetched, isLoading} = useGetEventPublic(eventId);
    const [previewSettings, setPreviewSettings] = useState<PreviewSettings | null>(null);

    useEffect(() => {
        const handleMessage = (messageEvent: MessageEvent) => {
            if (messageEvent.data.type === "UPDATE_SETTINGS") {
                setPreviewSettings(messageEvent.data.settings);
            }
        };

        window.addEventListener("message", handleMessage);
        return () => window.removeEventListener("message", handleMessage);
    }, []);

    if (!isFetched || isLoading) {
        return <LoadingOverlay visible />;
    }

    if (!event) {
        return <EventNotAvailable />;
    }

    // Create a modified event with preview settings merged in
    let previewEvent: Event | undefined = event;

    if (previewSettings && event.settings) {
        previewEvent = {
            ...event,
            settings: {
                ...event.settings,
                homepage_theme_settings: previewSettings.homepage_theme_settings as HomepageThemeSettings || event.settings.homepage_theme_settings,
                continue_button_text: previewSettings.continue_button_text ?? event.settings.continue_button_text,
                get_tickets_button_text: previewSettings.get_tickets_button_text ?? event.settings.get_tickets_button_text,
                homepage_blocks: previewSettings.homepage_blocks ?? event.settings.homepage_blocks,
            }
        };
    }

    // The preview renders the same room the public page does (system 1:1 by construction).
    return (
        <EventRoomShell event={previewEvent as Event}/>
    );
};

export default EventHomepagePreview;
