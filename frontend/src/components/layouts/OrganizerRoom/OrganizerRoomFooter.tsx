import {Anchor} from "@mantine/core";
import {t} from "@lingui/macro";
import {PoweredByFooter} from "../../common/PoweredByFooter";
import {CookieSettingsLink} from "../../common/CookieSettingsLink";
import {getConfig} from "../../../utilites/config.ts";

/**
 * The room's footer: the platform's own links and attribution, in monno's rhythm. The
 * attribution stays (it is an AGPL requirement unless a licence is purchased).
 */
export const OrganizerRoomFooter = () => (
    <footer className="cal-foot" data-od-id="organizer-footer">
        <div className="page">
            <div className="cal-foot-inner">
                <div className="cal-foot-links">
                    <Anchor href={getConfig('VITE_PRIVACY_URL', 'https://hi.events/privacy-policy')} target="_blank">
                        {t`Privacy Policy`}
                    </Anchor>
                    <Anchor href={getConfig('VITE_TOS_URL', 'https://hi.events/terms-of-service')} target="_blank">
                        {t`Terms of Service`}
                    </Anchor>
                    <CookieSettingsLink/>
                </div>
                <PoweredByFooter/>
            </div>
        </div>
    </footer>
);
