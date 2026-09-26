import {useLoaderData} from "react-router";
import {OrganizerRoom} from "../OrganizerRoom";
import {OrganizerRoomFooter} from "../OrganizerRoom/OrganizerRoomFooter.tsx";
import {StatusToggle} from "../../common/StatusToggle";
import {OrganizerRoomLoaderData} from "../../../routeLoaders/publicOrganizerRouteLoader.ts";
import {OrganizerNotFound} from "./OrganizerNotFound";

export const PublicOrganizer = () => {
    const loaderData = useLoaderData() as OrganizerRoomLoaderData;

    if (!loaderData?.organizer) {
        return <OrganizerNotFound/>;
    }

    const {organizer, upcoming, past, totals} = loaderData;
    const theme = organizer.settings?.homepage_theme_settings;

    return (
        <OrganizerRoom
            organizer={organizer}
            events={[...upcoming, ...past]}
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

export default PublicOrganizer;
