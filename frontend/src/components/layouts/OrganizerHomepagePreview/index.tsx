import {useEffect, useState} from "react";
import {useLoaderData, useParams} from "react-router";
import {useGetOrganizerSettings} from "../../../queries/useGetOrganizerSettings.ts";
import {LoadingMask} from "../../common/LoadingMask";
import {OrganizerRoom} from "../OrganizerRoom";
import {OrganizerRoomFooter} from "../OrganizerRoom/OrganizerRoomFooter.tsx";
import {StatusToggle} from "../../common/StatusToggle";
import {OrganizerRoomLoaderData} from "../../../routeLoaders/publicOrganizerRouteLoader.ts";
import {HomepageBlock, HomepageThemeSettings} from "../../../types.ts";

interface PreviewSettings {
    homepage_theme_settings?: HomepageThemeSettings;
    homepage_blocks?: HomepageBlock[];
    logoUrl?: string;
    coverUrl?: string;
}

const OrganizerHomepagePreview = () => {
    const {organizerId} = useParams();

    const {organizer, upcoming, past, totals} = useLoaderData() as OrganizerRoomLoaderData;

    const organizerSettingsQuery = useGetOrganizerSettings(organizerId);
    const [previewSettings, setPreviewSettings] = useState<PreviewSettings | null>(null);

    useEffect(() => {
        const handleMessage = (event: MessageEvent) => {
            if (event.data?.type === "UPDATE_ORGANIZER_SETTINGS") {
                setPreviewSettings(event.data.settings);
            }
        };

        window.addEventListener("message", handleMessage);

        // Tell the designer we are listening. It may have posted before this React tree
        // mounted, in which case its send-once guard would suppress the resend and the
        // preview would silently keep the saved settings.
        const announceReady = () => window.parent?.postMessage({type: "PREVIEW_READY"}, "*");
        announceReady();
        const readyTimer = window.setTimeout(announceReady, 500);

        return () => {
            window.clearTimeout(readyTimer);
            window.removeEventListener("message", handleMessage);
        };
    }, []);

    // Announce again once our own data has loaded: the designer may have sent before this
    // document was listening (its loader keeps the frame blank for a moment), and an edit made
    // in that window would otherwise be lost until the next change.
    useEffect(() => {
        if (!organizerSettingsQuery.isFetched) return;
        window.parent?.postMessage({type: "PREVIEW_READY"}, "*");
    }, [organizerSettingsQuery.isFetched]);

    if (!organizer || organizerSettingsQuery.isLoading || !organizerSettingsQuery.data) {
        return <LoadingMask/>;
    }

    // Merge preview settings with actual data (image swaps come through the same channel).
    const previewOrganizer = {
        ...organizer,
        images: organizer.images?.map(img => {
            if (img.type === 'ORGANIZER_LOGO' && previewSettings?.logoUrl) {
                return {...img, url: previewSettings.logoUrl};
            }
            if (img.type === 'ORGANIZER_COVER' && previewSettings?.coverUrl) {
                return {...img, url: previewSettings.coverUrl};
            }
            return img;
        }),
        settings: {
            ...organizerSettingsQuery.data,
            homepage_theme_settings: {
                ...organizerSettingsQuery.data.homepage_theme_settings,
                ...(previewSettings?.homepage_theme_settings ?? {}),
            },
            // Sections are page content: the preview must reflect edits before they are saved.
            homepage_blocks: previewSettings?.homepage_blocks ?? organizerSettingsQuery.data.homepage_blocks,
        }
    };

    const theme = previewOrganizer.settings?.homepage_theme_settings;

    return (
        <OrganizerRoom
            organizer={previewOrganizer}
            blocks={previewSettings?.homepage_blocks ?? organizerSettingsQuery.data.homepage_blocks}
            events={[...(upcoming ?? []), ...(past ?? [])]}
            totals={totals}
            mode={theme?.mode === 'dark' ? 'dark' : 'light'}
            theme={theme}
            banner={organizer.id && organizer.status ? (
                <div className="room-banner">
                    <StatusToggle
                        entityType="organizer"
                        entityId={organizer.id}
                        currentStatus={organizer.status as 'DRAFT' | 'LIVE' | 'PENDING_MANUAL_REVIEW'}
                        entityName={organizer.name}
                        onSuccess={() => window.location.reload()}
                    />
                </div>
            ) : null}
            footer={<OrganizerRoomFooter/>}
        />
    );
};

export default OrganizerHomepagePreview;
