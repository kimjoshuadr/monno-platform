import {useLoaderData} from "react-router";
import {EventRoomShell} from "../EventRoom/EventRoomShell.tsx";
import {EventNotAvailable} from "../EventHomepage/EventNotAvailable";
import {Event} from "../../../types";

export const PublicEvent = () => {
    const loaderData = useLoaderData();

    const {event, promoCodeValid, promoCode, occurrenceId} = loaderData as {
        event?: Event;
        promoCodeValid?: boolean;
        promoCode?: string;
        occurrenceId?: number | null;
    };

    if (!event) {
        return <EventNotAvailable/>;
    }

    // The shell owns the theme, head, fonts and tracking pixels; the room is monno's design.
    return (
        <EventRoomShell
            event={event}
            promoCode={promoCode}
            promoCodeValid={promoCodeValid}
            initialOccurrenceId={occurrenceId}
        />
    );
};

export default PublicEvent;
