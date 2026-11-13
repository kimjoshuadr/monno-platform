import {useParams} from "react-router";
import {PageBody} from "../../../common/PageBody";
import {WidgetEditor} from "../../../common/WidgetEditor";

/** Organizer-level embeds: the calendar first, any event for the rest. */
const OrganizerEmbeds = () => {
    const {organizerId} = useParams();
    return (
        <PageBody>
            <WidgetEditor organizerId={organizerId}/>
        </PageBody>
    );
};

export default OrganizerEmbeds;
