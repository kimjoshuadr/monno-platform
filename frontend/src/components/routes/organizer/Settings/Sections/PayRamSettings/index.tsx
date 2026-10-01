import {t} from "@lingui/macro";
import {Alert, Anchor, Button, Code, CopyButton, Group, Loader, Stack, Text} from "@mantine/core";
import {IconAlertCircle, IconCopy, IconExternalLink, IconInfoCircle, IconWallet} from "@tabler/icons-react";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {useGetPayRamAccount} from "../../../../../../queries/useGetPayRamAccount";
import {useEnsurePayRamAccount} from "../../../../../../mutations/useEnsurePayRamAccount";
import {showError, showSuccess} from "../../../../../../utilites/notifications";
import {IdParam} from "../../../../../../types";

interface PayRamSettingsProps {
    organizerId: IdParam;
}

const messageFromError = (error: unknown): string | undefined =>
    (error as {response?: {data?: {message?: string}}})?.response?.data?.message;

export const PayRamSettings = ({organizerId}: PayRamSettingsProps) => {
    const {data, isPending} = useGetPayRamAccount(organizerId);
    const ensureAccount = useEnsurePayRamAccount();

    const status = data?.status ?? 'NOT_CONNECTED';

    const handleSetup = () => {
        ensureAccount.mutate({organizerId}, {
            onSuccess: (account) => {
                if (account.credentials) {
                    // Passwords are handed over once — don't toast over the panel.
                    return;
                }
                showSuccess(t`Your PayRam account is ready`);
            },
            onError: (error: unknown) => {
                showError(messageFromError(error) || t`Could not set up PayRam. Please try again.`);
            },
        });
    };

    const setupButton = (label: string) => (
        <Button
            leftSection={<IconWallet size={16}/>}
            loading={ensureAccount.isPending}
            onClick={handleSetup}
            data-testid="payram-setup-button"
        >
            {label}
        </Button>
    );

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Crypto payments`}
                description={t`Let buyers pay in stablecoins. Their money settles to your wallet on-chain, and monno takes its platform fee automatically in the same transaction.`}
            />

            <Stack gap="md">
                {isPending && <Loader size="sm"/>}

                {!isPending && status === 'NOT_CONNECTED' && (
                    <Stack gap="sm">
                        <Text size="sm" c="dimmed">
                            {t`Setting this up creates your own PayRam merchant account — you get your own dashboard, your own wallets, and your own reporting.`}
                        </Text>
                        {setupButton(t`Set up crypto payments`)}
                    </Stack>
                )}

                {!isPending && status === 'PROVISIONING' && (
                    <Group gap="sm">
                        <Loader size="sm"/>
                        <Text size="sm">{t`Setting up your account...`}</Text>
                    </Group>
                )}

                {!isPending && status === 'FAILED' && (
                    <Stack gap="sm">
                        <Alert color="red" icon={<IconAlertCircle size={16}/>} title={t`Setup failed`}>
                            {data?.last_error || t`The payment gateway rejected the request. Please try again.`}
                        </Alert>
                        {setupButton(t`Try again`)}
                    </Stack>
                )}

                {!isPending && status === 'READY' && (
                    <Stack gap="sm">
                        <Group justify="space-between">
                            <Text size="sm">
                                {t`Signed in as`} <Code>{data?.member_email}</Code>
                            </Text>
                            {data?.dashboard_url && (
                                // eslint-disable-next-line lingui/no-unlocalized-strings -- opener security attribute, not user-facing copy
                                <Anchor href={data.dashboard_url} target="_blank" rel="noopener noreferrer" size="sm">
                                    <Group gap={4} wrap="nowrap">
                                        <IconExternalLink size={14}/>
                                        {t`Open PayRam dashboard`}
                                    </Group>
                                </Anchor>
                            )}
                        </Group>

                        {data?.wallet_status === 'NOT_CONFIGURED' && (
                            <Alert color="yellow" icon={<IconInfoCircle size={16}/>} title={t`Wallet setup required`}>
                                {t`Finish wallet setup in your PayRam dashboard before buyers can pay. This is where you choose the wallet your money is swept to — monno never holds your keys.`}
                            </Alert>
                        )}

                        {data?.credentials && (
                            <Alert color="blue" icon={<IconWallet size={16}/>} title={t`Save these now`}>
                                <Stack gap={4}>
                                    <Text size="sm">
                                        {t`These are shown once. Use them to sign in to PayRam, then set your own password.`}
                                    </Text>
                                    <Group gap="xs">
                                        <Code>{data.credentials.email}</Code>
                                        <CopyButton value={data.credentials.password}>
                                            {({copied, copy}) => (
                                                <Button
                                                    size="compact-xs"
                                                    variant="light"
                                                    leftSection={<IconCopy size={12}/>}
                                                    onClick={copy}
                                                >
                                                    {copied ? t`Copied` : t`Copy password`}
                                                </Button>
                                            )}
                                        </CopyButton>
                                    </Group>
                                    <Code>{data.credentials.password}</Code>
                                </Stack>
                            </Alert>
                        )}
                    </Stack>
                )}
            </Stack>
        </Card>
    );
}
