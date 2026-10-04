import {useState} from "react";
import {t} from "@lingui/macro";
import {
    Alert,
    Badge,
    Button,
    Group,
    Loader,
    Paper,
    Stack,
    Text,
    ThemeIcon,
} from "@mantine/core";
import {
    IconAlertCircle,
    IconExternalLink,
    IconShieldCheck,
    IconWallet,
} from "@tabler/icons-react";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {useGetPayRamAccount} from "../../../../../../queries/useGetPayRamAccount";
import {IdParam} from "../../../../../../types";
import {organizerPayRamClient} from "../../../../../../api/organizer-payram.client";
import {showInfo} from "../../../../../../utilites/notifications";

interface PayRamSettingsProps {
    organizerId: IdParam;
}

export const PayRamSettings = ({organizerId}: PayRamSettingsProps) => {
    const {data, isPending} = useGetPayRamAccount(organizerId);
    const [isOpeningConsole, setIsOpeningConsole] = useState(false);

    const isConnected = data?.status === 'READY';

    // The gateway is authoritative about whether a payout wallet is actually
    // attached. Our own record can say READY while PayRam disagrees — or while
    // we simply cannot reach PayRam — and the organizer deserves the difference.
    const gateway = data?.gateway;
    const gatewayWalletReady = gateway?.available ? gateway.cold_wallet_configured : null;

    // Which networks can take money, and which are still unfinished. Each
    // deposit wallet is its own contract, and the organizer picks which to
    // accept, so this is per-network rather than one global yes/no.
    const networks = gateway?.networks ?? [];
    const readyNetworks = networks.filter((n) => n.cold_wallet_configured);
    const pendingNetworks = networks.filter((n) => !n.cold_wallet_configured);

    // Only the gateway's own confirmation means crypto is genuinely live. Our
    // saved address is a record of what the organizer asked for, not proof that
    // PayRam attached it, so it must never be enough on its own.
    const isLive = isConnected && gatewayWalletReady === true;

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
            const wallet = needsWalletSetup ? 'needs' : 'ok';

            // Where to send them back. The console guide shows every chain when
            // it has no selection (see monno-payram/public/monno-ui.js) — the
            // chains an organizer accepts are configured in PayRam now, not here.
            const back = `${window.location.origin}/manage/organizer/${organizerId}/settings#crypto-payments`;

            const fragment = new URLSearchParams({code, exchange: exchange_url, redirect, back, wallet}).toString();
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

                {!isPending && !isLive && data?.status !== 'FAILED' && (
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

                            {isConnected && gatewayWalletReady === null && (
                                <Alert
                                    color="orange"
                                    variant="light"
                                    icon={<IconAlertCircle size={14} />}
                                    p="xs"
                                >
                                    <Text size="xs" fw={500} mb={4}>
                                        {t`Wallet setup not confirmed`}
                                    </Text>
                                    <Text size="xs" c="dimmed">
                                        {t`We could not reach PayRam to confirm your wallets, so crypto is not live yet. Set up (or recheck) the networks you accept in the PayRam console — one is enough to start.`}
                                    </Text>
                                </Alert>
                            )}

                            {isConnected && gatewayWalletReady === false && (
                                <Alert
                                    color="orange"
                                    variant="light"
                                    icon={<IconAlertCircle size={14} />}
                                    p="xs"
                                >
                                    <Text size="xs" fw={500} mb={4}>
                                        {t`One step remaining: finish your wallets in PayRam`}
                                    </Text>
                                    <Text size="xs" c="dimmed">
                                        {t`Creating a deposit wallet also sets the cold wallet your sales sweep to, so it is one flow per network. Only set up the networks you want to accept — one is enough.`}
                                    </Text>
                                </Alert>
                            )}

                            <Group gap="xs" mt="xs">
                                <Button
                                    leftSection={<IconShieldCheck size={16} />}
                                    color="indigo"
                                    rightSection={<IconExternalLink size={16} />}
                                    loading={isOpeningConsole}
                                    onClick={handleOpenConsole}
                                    data-testid="payram-setup-button"
                                >
                                    {t`Set up wallets in PayRam`}
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

                {!isPending && isLive && (
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
                                        variant="subtle"
                                        size="xs"
                                        rightSection={<IconExternalLink size={14} />}
                                        loading={isOpeningConsole}
                                        onClick={handleOpenConsole}
                                    >
                                        {t`PayRam console`}
                                    </Button>
                                </Group>
                            </Group>

                            <Stack gap="xs">
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
                                            {t`One step remaining: set up your wallets in PayRam`}
                                        </Text>
                                        <Text size="xs" c="dimmed">
                                            {t`Open the PayRam console and set up your deposit wallet and the payout wallet your sales sweep to. That is where PayRam attaches them — Monno only reports what it confirms.`}
                                        </Text>
                                    </Alert>
                                )}

                                {gateway?.available && gatewayWalletReady && (
                                    <Stack gap={4}>
                                        <Group justify="space-between">
                                            <Text size="xs" c="dimmed">{t`Accepting payments on`}</Text>
                                            <Group gap={4}>
                                                {readyNetworks.map((n, i) => (
                                                    <Badge key={i} size="xs" variant="light" color="teal">
                                                        {n.blockchain_code || n.wallet_name || t`Network`}
                                                    </Badge>
                                                ))}
                                            </Group>
                                        </Group>
                                        {pendingNetworks.length > 0 && (
                                            <Text size="xs" c="dimmed">
                                                {t`Not set up (optional):`}{' '}
                                                {pendingNetworks
                                                    .map((n) => n.blockchain_code || n.wallet_name || '')
                                                    .filter(Boolean)
                                                    .join(', ')}
                                            </Text>
                                        )}
                                    </Stack>
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

                                {gateway?.available && (gateway.recent_settlements?.length ?? 0) > 0 && (
                                    <Group justify="space-between">
                                        <Text size="xs" c="dimmed">{t`Last settled`}</Text>
                                        <Text size="xs" fw={500}>
                                            {`${gateway.recent_settlements![0].amount} ${gateway.recent_settlements![0].currency_code ?? ''}`.trim()}
                                            {gateway.recent_settlements![0].destination
                                                ? ` → ${gateway.recent_settlements![0].destination!.slice(0, 6)}…${gateway.recent_settlements![0].destination!.slice(-4)}`
                                                : ''}
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
        </Card>
    );
};
