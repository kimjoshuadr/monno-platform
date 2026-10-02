import {useState} from "react";
import {t} from "@lingui/macro";
import {
    Alert,
    Badge,
    Button,
    Code,
    CopyButton,
    Group,
    Loader,
    Paper,
    Stack,
    Text,
    ThemeIcon,
} from "@mantine/core";
import {
    IconAlertCircle,
    IconCheck,
    IconCopy,
    IconEdit,
    IconExternalLink,
    IconShieldCheck,
    IconWallet,
} from "@tabler/icons-react";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {useGetPayRamAccount} from "../../../../../../queries/useGetPayRamAccount";
import {IdParam} from "../../../../../../types";
import {CryptoConnectWizardModal} from "./CryptoConnectWizardModal";
import {organizerPayRamClient} from "../../../../../../api/organizer-payram.client";
import {showInfo} from "../../../../../../utilites/notifications";

interface PayRamSettingsProps {
    organizerId: IdParam;
}

export const PayRamSettings = ({organizerId}: PayRamSettingsProps) => {
    const {data, isPending} = useGetPayRamAccount(organizerId);
    const [isWizardOpen, setIsWizardOpen] = useState(false);
    const [isOpeningConsole, setIsOpeningConsole] = useState(false);

    const isConnected = data?.status === 'READY';
    const isWalletConfigured = Boolean(
        data?.wallet_status === 'READY' || data?.wallet_address
    );

    // The gateway is authoritative about whether a payout wallet is actually
    // attached. Our own record can say READY while PayRam disagrees, and the
    // organizer deserves to see the difference.
    const gateway = data?.gateway;
    const gatewayWalletReady = gateway?.available ? gateway.cold_wallet_configured : null;

    // Hand the gateway a one-time code via the URL fragment; its /sso.html page
    // (shipped in our PayRam image) redeems it and signs the organizer in, so
    // they never see a password or a forced reset.
    //
    // Deep-link to the wallet setup page for organizers whose gateway payout
    // wallet isn't confirmed yet — they need to complete exactly one action
    // there and we don't want them hunting for it.
    const handleOpenConsole = async () => {
        try {
            setIsOpeningConsole(true);
            const {code, dashboard_url, exchange_url} = await organizerPayRamClient.createSsoToken(organizerId);

            const needsWalletSetup = !gatewayWalletReady;
            const redirect = needsWalletSetup ? '/manageWallet/deposit-wallet' : '/dashboard';

            const fragment = new URLSearchParams({code, exchange: exchange_url, redirect}).toString();
            window.open(`${dashboard_url}/sso.html#${fragment}`, '_blank', 'noopener,noreferrer');
        } catch {
            // We could not mint a session — most likely this merchant's login
            // predates us storing its password. Open the console anyway so the
            // organizer can use "Forgot password" instead of hitting a dead end.
            showInfo(t`Could not sign you in automatically. Opening the console — use "Forgot password" if you don't have your login.`);
            window.open(data?.dashboard_url || 'https://pay.monno.io', '_blank', 'noopener,noreferrer');
        } finally {
            setIsOpeningConsole(false);
        }
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Crypto payments`}
                description={t`Accept USDC, USDT, ETH, and other cryptocurrencies with zero chargebacks. Sales settle to your own cold wallet on-chain.`}
            />

            <Stack gap="md" mt="sm">
                {isPending && <Loader size="sm" />}

                {!isPending && (!isConnected || !isWalletConfigured) && (
                    <Paper
                        p="lg"
                        withBorder
                        style={{
                            background: 'linear-gradient(135deg, rgba(99, 102, 241, 0.05) 0%, rgba(168, 85, 247, 0.05) 100%)',
                            borderColor: 'rgba(99, 102, 241, 0.2)',
                        }}
                    >
                        <Stack gap="sm">
                            <Group justify="space-between" align="flex-start">
                                <Group gap="sm">
                                    <ThemeIcon size="lg" radius="md" color="indigo" variant="light">
                                        <IconWallet size={20} />
                                    </ThemeIcon>
                                    <div>
                                        <Text fw={600} size="sm">
                                            {t`Crypto payments, powered by PayRam`}
                                        </Text>
                                        <Text size="xs" c="dimmed">
                                            {t`Accept stablecoins and other crypto with zero chargebacks.`}
                                        </Text>
                                    </div>
                                </Group>
                                <Badge color="indigo" variant="light" size="sm">
                                    {t`Non-custodial`}
                                </Badge>
                            </Group>

                            <Text size="sm" c="dimmed">
                                {t`Choose the currencies you accept and record the wallet your sales settle to. Settling to that wallet needs one wallet connection in the PayRam console — we never ask for or hold your private keys.`}
                            </Text>

                            <Group gap="xs" mt="xs">
                                <Button
                                    leftSection={<IconShieldCheck size={16} />}
                                    color="indigo"
                                    onClick={() => setIsWizardOpen(true)}
                                    data-testid="payram-setup-button"
                                >
                                    {t`Set up crypto payments`}
                                </Button>
                            </Group>
                        </Stack>
                    </Paper>
                )}

                {!isPending && data?.status === 'FAILED' && (
                    <Alert color="red" icon={<IconAlertCircle size={16} />} title={t`Setup encountered an issue`}>
                        {data?.last_error || t`Could not initialize crypto payments. Please click setup to try again.`}
                    </Alert>
                )}

                {!isPending && isConnected && isWalletConfigured && (
                    <Paper p="md" withBorder>
                        <Stack gap="md">
                            <Group justify="space-between" align="center">
                                <Group gap="xs">
                                    <Badge color="teal" variant="filled" size="sm">
                                        {t`Active`}
                                    </Badge>
                                    <Text fw={600} size="sm">
                                        {t`Crypto payments are ready`}
                                    </Text>
                                </Group>
                                <Group gap="xs">
                                    <Button
                                        variant="default"
                                        size="xs"
                                        leftSection={<IconEdit size={14} />}
                                        onClick={() => setIsWizardOpen(true)}
                                    >
                                        {t`Edit setup`}
                                    </Button>
                                    <Button
                                        variant={gatewayWalletReady === false ? 'filled' : 'subtle'}
                                        color={gatewayWalletReady === false ? 'orange' : undefined}
                                        size="xs"
                                        rightSection={<IconExternalLink size={14} />}
                                        loading={isOpeningConsole}
                                        onClick={handleOpenConsole}
                                    >
                                        {gatewayWalletReady === false
                                            ? t`Finish wallet setup ↗`
                                            : t`PayRam console`}
                                    </Button>
                                </Group>
                            </Group>

                            <Stack gap="xs">
                                {data?.wallet_address && (
                                    <Group justify="space-between" wrap="nowrap">
                                        <Text size="xs" c="dimmed">
                                            {t`Payout cold wallet`}
                                        </Text>
                                        <Group gap={6} wrap="nowrap">
                                            <Code style={{fontSize: 12, wordBreak: 'break-all'}}>
                                                {data.wallet_address}
                                            </Code>
                                            <CopyButton value={data.wallet_address}>
                                                {({copied, copy}) => (
                                                    <Button
                                                        size="compact-xs"
                                                        variant="subtle"
                                                        color={copied ? 'teal' : 'gray'}
                                                        onClick={copy}
                                                    >
                                                        {copied ? <IconCheck size={12} /> : <IconCopy size={12} />}
                                                    </Button>
                                                )}
                                            </CopyButton>
                                        </Group>
                                    </Group>
                                )}

                                <Group justify="space-between">
                                    <Text size="xs" c="dimmed">
                                        {t`Supported tokens`}
                                    </Text>
                                    <Group gap={4}>
                                        {(data?.supported_currencies && data.supported_currencies.length > 0
                                            ? data.supported_currencies
                                            : ['USDC', 'USDT', 'ETH', 'POL']
                                        ).map((token) => (
                                            <Badge key={token} size="xs" variant="outline" color="gray">
                                                {token}
                                            </Badge>
                                        ))}
                                    </Group>
                                </Group>

                                <Group justify="space-between">
                                    <Text size="xs" c="dimmed">
                                        {t`Platform fee`}
                                    </Text>
                                    <Text size="xs" fw={500}>
                                        {t`2.5% (automatically collected on-chain)`}
                                    </Text>
                                </Group>

                                <Group justify="space-between">
                                    <Text size="xs" c="dimmed">
                                        {t`Settlement`}
                                    </Text>
                                    <Text size="xs" fw={500}>
                                        {t`Instant sweep to your cold wallet`}
                                    </Text>
                                </Group>

                                {gateway?.available && !gatewayWalletReady && (
                                    <Alert
                                        color="orange"
                                        variant="light"
                                        icon={<IconAlertCircle size={14}/>}
                                        p="xs"
                                    >
                                        <Text size="xs" fw={500} mb={4}>
                                            {t`One step remaining: connect your payout wallet`}
                                        </Text>
                                        <Text size="xs" c="dimmed">
                                            {t`Click "Finish wallet setup" above. You'll be taken directly to the wallet setup page in the PayRam console where you connect MetaMask and set your payout address. This takes about 2 minutes and only happens once.`}
                                        </Text>
                                    </Alert>
                                )}

                                {gateway?.available && gatewayWalletReady && (
                                    <Group justify="space-between">
                                        <Text size="xs" c="dimmed">{t`Gateway payout wallet`}</Text>
                                        <Badge size="xs" variant="light" color="teal">
                                            {t`Confirmed`}
                                        </Badge>
                                    </Group>
                                )}

                                {gateway?.available && gateway.eligible_for_sweep.length > 0 && (
                                    <Group justify="space-between">
                                        <Text size="xs" c="dimmed">{t`Awaiting sweep`}</Text>
                                        <Text size="xs" fw={500}>
                                            {gateway.eligible_for_sweep
                                                .map((entry) => `${entry.amount} ${entry.currency_code ?? ''}`.trim())
                                                .join(', ')}
                                        </Text>
                                    </Group>
                                )}

                                {gateway?.available === false && (
                                    <Text size="xs" c="dimmed">
                                        {t`Could not reach the payment gateway to confirm wallet status.`}
                                    </Text>
                                )}

                                {gateway?.last_sweep_error?.reason && (
                                    <Alert color="orange" variant="light" icon={<IconAlertCircle size={14}/>} p="xs">
                                        <Text size="xs">{gateway.last_sweep_error.reason}</Text>
                                    </Alert>
                                )}
                            </Stack>
                        </Stack>
                    </Paper>
                )}
            </Stack>

            <CryptoConnectWizardModal
                opened={isWizardOpen}
                onClose={() => setIsWizardOpen(false)}
                organizerId={organizerId}
                existingAccount={data}
            />
        </Card>
    );
};
