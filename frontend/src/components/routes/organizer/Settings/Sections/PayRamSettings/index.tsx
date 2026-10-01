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

interface PayRamSettingsProps {
    organizerId: IdParam;
}

export const PayRamSettings = ({organizerId}: PayRamSettingsProps) => {
    const {data, isPending} = useGetPayRamAccount(organizerId);
    const [isWizardOpen, setIsWizardOpen] = useState(false);

    const isConnected = data?.status === 'READY';
    const isWalletConfigured = Boolean(
        data?.wallet_status === 'READY' || data?.wallet_address
    );

    // The gateway is authoritative about whether a payout wallet is actually
    // attached. Our own record can say READY while PayRam disagrees, and the
    // organizer deserves to see the difference.
    const gateway = data?.gateway;
    const gatewayWalletReady = gateway?.available ? gateway.cold_wallet_configured : null;

    // The console is a separate app we do not control: it has no token-login
    // route we can deep-link into, so we can only open it. Until the gateway
    // gains an SSO accept route, the organizer signs in with the credentials
    // they were given when crypto was first set up.
    const handleOpenConsole = () => {
        window.open(data?.dashboard_url || 'https://pay.monno.io', '_blank', 'noopener,noreferrer');
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Crypto payments`}
                description={t`Accept USDC, USDT, ETH, and other cryptocurrencies with zero chargebacks. Sales settle instantly to your cold wallet on-chain.`}
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
                                            {t`Monno Crypto Connect`}
                                        </Text>
                                        <Text size="xs" c="dimmed">
                                            {t`Stripe-like guided setup for Web3 payments`}
                                        </Text>
                                    </div>
                                </Group>
                                <Badge color="indigo" variant="light" size="sm">
                                    {t`Non-custodial`}
                                </Badge>
                            </Group>

                            <Text size="sm" c="dimmed">
                                {t`Set up your payout cold wallet and choose accepted currencies in under 2 minutes. We automatically deploy smart contract routing so payments sweep straight to your custody.`}
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
                                        {t`Monno Crypto Connect is ready`}
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
                                        variant="subtle"
                                        size="xs"
                                        rightSection={<IconExternalLink size={14} />}
                                        onClick={handleOpenConsole}
                                    >
                                        {t`PayRam console`}
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

                                {gateway?.available && (
                                    <Group justify="space-between">
                                        <Text size="xs" c="dimmed">{t`Gateway payout wallet`}</Text>
                                        <Badge size="xs" variant="light" color={gatewayWalletReady ? 'teal' : 'orange'}>
                                            {gatewayWalletReady ? t`Confirmed` : t`Not configured`}
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
