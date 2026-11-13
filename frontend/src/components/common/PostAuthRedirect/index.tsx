import {Navigate} from "react-router";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {useGetOrganizers} from "../../../queries/useGetOrganizers.ts";

/**
 * Resolves where a signed-in user should land — in ONE redirect.
 *
 * The rules, in order:
 *   - not signed in                    -> /auth/login
 *   - no organizer account (a buyer)   -> /welcome  (onboarding; the API
 *     returns an empty organizer list for them and provisions the account on
 *     first organizer create, so onboarding is where they belong)
 *   - signed up, zero organizers yet   -> /welcome  (first-time setup)
 *   - exactly one organizer            -> its dashboard
 *   - more than one                    -> /manage/events (the account list)
 *
 * Auth pages do NOT use this for account-less sessions: a buyer there stays on
 * the login/register form, so clicking Login or Signup behaves normally.
 *
 * userClient.me() unwraps inconsistently across payloads, so account_id is read
 * at either level — same defensive read Root used.
 */
export const accountIdOf = (data: unknown): number | string | undefined => {
    const payload = data as
        | { data?: { account_id?: number | string }; account_id?: number | string }
        | undefined;

    return payload?.data?.account_id ?? payload?.account_id;
};

export const PostAuthRedirect = ({
    search = '',
    accountlessPath = '/welcome',
}: { search?: string; accountlessPath?: string }) => {
    const me = useGetMe();

    if (!me.isFetched) {
        return null;
    }

    if (!me.isSuccess) {
        return <Navigate to={'/auth/login' + search} replace={true}/>;
    }

    // Unverified organizer: the dashboard is locked server-side, so park here
    // rather than let them bounce off a 403 on every query in the layout.
    if (me.data.enforce_email_confirmation_during_registration && !me.data.is_email_verified) {
        return <Navigate to={'/verify-email' + search} replace={true}/>;
    }

    if (!accountIdOf(me.data)) {
        return <Navigate to={accountlessPath + search} replace={true}/>;
    }

    // Only an organizer-team member gets here, so the organizer query is safe.
    return <OrganizerDestination search={search}/>;
};

const OrganizerDestination = ({search}: {search: string}) => {
    const organizersQuery = useGetOrganizers();

    if (!organizersQuery.isFetched) {
        return null;
    }

    const organizers = organizersQuery.data?.data ?? [];

    if (organizers.length === 0) {
        return <Navigate to={'/welcome' + search} replace={true}/>;
    }

    if (organizers.length === 1) {
        return <Navigate to={`/manage/organizer/${organizers[0].id}${search}`} replace={true}/>;
    }

    return <Navigate to={'/manage/events' + search} replace={true}/>;
};

export default PostAuthRedirect;
