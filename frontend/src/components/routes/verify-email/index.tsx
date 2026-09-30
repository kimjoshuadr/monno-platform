import {Container, Stack, Text} from "@mantine/core";
import {Trans} from "@lingui/macro";
import {Card} from "../../common/Card";
import {LoadingMask} from "../../common/LoadingMask";
import {PostAuthRedirect} from "../../common/PostAuthRedirect";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {getConfig} from "../../../utilites/config.ts";
import {ConfirmVerificationPin} from "../welcome";
// The step styles live with the onboarding wizard that first used them; both
// screens are the same card, so there is one stylesheet rather than two copies.
import classes from "../welcome/Welcome.module.scss";

/**
 * The parked screen for an organizer whose email is not confirmed yet.
 *
 * Reached three ways, all of which land here rather than in a half-working
 * dashboard: after login (PostAuthRedirect), when an API call answers
 * `email_not_verified` (the axios interceptor), or by typing the URL.
 *
 * Once the address is confirmed the me query refetches, `is_email_verified`
 * flips, and PostAuthRedirect picks the real destination — so leaving here and
 * arriving there is one code path shared with a normal login.
 */
export const VerifyEmail = () => {
    const me = useGetMe();

    if (!me.isFetched) {
        return <LoadingMask/>;
    }

    // Expired session while parked here: PostAuthRedirect sends it to login.
    // Verification switched off, or just completed: it carries on to the
    // dashboard instead of showing a step nobody needs.
    if (me.isError || !me.data
        || !me.data.enforce_email_confirmation_during_registration
        || me.data.is_email_verified) {
        return <PostAuthRedirect/>;
    }

    return (
        <div className={classes.welcomeContainer}>
            <Container size="sm" className={classes.welcomeContent}>
                <div className={classes.welcomeHeader}>
                    <div className={classes.logo}>
                        <img
                            src={getConfig("VITE_APP_LOGO_LIGHT", "/logos/monno-text-dark.svg")}
                            alt={`${getConfig("VITE_APP_NAME", "monno")} logo`}
                            className={classes.logo}
                        />
                    </div>
                    <h1 className={classes.welcomeTitle}>
                        <Trans>Verify your email</Trans>
                    </h1>
                </div>

                <Card className={classes.welcomeCard}>
                    <ConfirmVerificationPin/>
                </Card>

                <Stack gap={4} mt="md" align="center">
                    <Text size="sm" c="dimmed" ta="center">
                        <Trans>Signed in as {me.data.email}</Trans>
                    </Text>
                </Stack>
            </Container>
        </div>
    );
};

export default VerifyEmail;
