import {useState, useEffect} from 'react';
import {
    Modal,
    Button,
    Group,
    Stack,
    Text,
    TextInput,
    Checkbox,
    Badge,
    Alert,
    ThemeIcon,
    Loader,
    Box,
    Paper,
} from '@mantine/core';
import {
    IconWallet,
    IconCheck,
    IconShieldCheck,
    IconArrowRight,
    IconArrowLeft,
    IconAlertCircle,
} from '@tabler/icons-react';
import {t} from '@lingui/macro';
import {IdParam, OrganizerPayRamAccountResponse} from '../../../../../../types';
import {useSetupPayRamCryptoConnect} from '../../../../../../mutations/useSetupPayRamCryptoConnect';
import {showError, showSuccess} from '../../../../../../utilites/notifications';

interface CryptoConnectWizardModalProps {
    opened: boolean;
    onClose: () => void;
    organizerId: IdParam;
    existingAccount?: OrganizerPayRamAccountResponse;
}

const SUPPORTED_NETWORKS = [
    {
        id: 'evm',
        name: 'EVM Networks (Ethereum, Base, Polygon)',
        recommended: true,
        tokens: ['USDC', 'USDT', 'ETH', 'POL'],
        description: 'Low gas, instant settlement across leading Ethereum Layer-2s and Mainnet.',
    },
    {
        id: 'tron',
        name: 'Tron Network',
        recommended: false,
        tokens: ['USDT (TRC-20)', 'TRX'],
        description: 'Popular for global low-cost USDT transfers.',
    },
    {
        id: 'bitcoin',
        name: 'Bitcoin',
        recommended: false,
        tokens: ['BTC'],
        description: 'Native Bitcoin payments.',
    },
];

export const CryptoConnectWizardModal = ({
    opened,
    onClose,
    organizerId,
    existingAccount,
}: CryptoConnectWizardModalProps) => {
    const [step, setStep] = useState(0);
    const [selectedNetworks, setSelectedNetworks] = useState<string[]>(['evm']);
    const [walletAddress, setWalletAddress] = useState(existingAccount?.wallet_address || '');
    const [tronAddress, setTronAddress] = useState('');
    const [btcAddress, setBtcAddress] = useState('');
    const [walletError, setWalletError] = useState<string | null>(null);
    const [isConnectingWallet, setIsConnectingWallet] = useState(false);
    const [hasInjectedWallet, setHasInjectedWallet] = useState(false);
    const [activationResult, setActivationResult] = useState<OrganizerPayRamAccountResponse | null>(null);

    const setupMutation = useSetupPayRamCryptoConnect();

    useEffect(() => {
        if (existingAccount?.wallet_address) {
            setWalletAddress(existingAccount.wallet_address);
        }
    }, [existingAccount]);

    useEffect(() => {
        if (typeof window !== 'undefined' && (window as any).ethereum) {
            setHasInjectedWallet(true);
        }
    }, []);

    const toggleNetwork = (id: string) => {
        if (id === 'evm') {
            // EVM is required for default settlement
            return;
        }
        setSelectedNetworks((prev) =>
            prev.includes(id) ? prev.filter((n) => n !== id) : [...prev, id]
        );
    };

    const handleConnectWeb3Wallet = async () => {
        if (typeof window === 'undefined' || !(window as any).ethereum) {
            showError(t`No Web3 wallet extension found (e.g. MetaMask, Rainbow).`);
            return;
        }

        try {
            setIsConnectingWallet(true);
            const accounts = await (window as any).ethereum.request({
                method: 'eth_requestAccounts',
            });
            if (accounts && accounts[0]) {
                setWalletAddress(accounts[0]);
                setWalletError(null);
                showSuccess(t`Wallet connected: ${accounts[0].slice(0, 6)}...${accounts[0].slice(-4)}`);
            }
        } catch (error: any) {
            showError(error?.message || t`Failed to connect wallet.`);
        } finally {
            setIsConnectingWallet(false);
        }
    };

    const validateAndProceedToConfirm = () => {
        const clean = walletAddress.trim();
        if (!clean) {
            setWalletError(t`Please enter your payout cold wallet address.`);
            return;
        }
        if (!/^0x[a-fA-F0-9]{40}$/.test(clean)) {
            setWalletError(t`Invalid EVM address. Must start with 0x followed by 40 hex characters.`);
            return;
        }

        setWalletError(null);
        setStep(2); // Jump to automated provisioning & activate
        executeActivation(clean);
    };

    const executeActivation = (addressToUse: string) => {
        const currencies = ['ETH', 'USDC', 'USDT', 'POL', 'BASE_ETH', 'BASE_USDC'];
        if (selectedNetworks.includes('tron')) {
            currencies.push('TRX', 'TRON_USDT');
        }
        if (selectedNetworks.includes('bitcoin')) {
            currencies.push('BTC');
        }

        setupMutation.mutate(
            {
                organizerId,
                data: {
                    wallet_address: addressToUse,
                    currencies,
                    tron_wallet_address: tronAddress || undefined,
                    btc_wallet_address: btcAddress || undefined,
                },
            },
            {
                onSuccess: (data) => {
                    setActivationResult(data);
                    setStep(3); // Success step
                },
                onError: (error: any) => {
                    showError(
                        error?.response?.data?.message ||
                            t`Could not activate crypto payments. Please check your address and try again.`
                    );
                    setStep(1); // Return to address step
                },
            }
        );
    };

    const handleModalClose = () => {
        if (!setupMutation.isPending) {
            onClose();
        }
    };

    // The gateway is the source of truth on whether a payout wallet is attached.
    // `null` means we could not reach it and simply do not know.
    const gatewayConfirmed = activationResult?.gateway?.available
        ? activationResult.gateway.cold_wallet_configured
        : null;

    return (
        <Modal
            opened={opened}
            onClose={handleModalClose}
            title={
                <Group gap={8}>
                    <IconShieldCheck size={22} color="#6366f1" />
                    <Text fw={700} size="lg">
                        {t`Monno Crypto Connect Setup`}
                    </Text>
                </Group>
            }
            size="lg"
            centered
            closeOnClickOutside={!setupMutation.isPending}
            closeOnEscape={!setupMutation.isPending}
        >
            <Box py="xs">
                {/* Step indicator */}
                <Group justify="center" mb="lg" gap="xs">
                    {['Networks & Tokens', 'Payout Wallet', 'Activation'].map((label, idx) => {
                        const current = step === idx || (step === 3 && idx === 2);
                        const done = step > idx;
                        return (
                            <Group key={label} gap={6}>
                                <Badge
                                    size="sm"
                                    circle
                                    color={done ? 'teal' : current ? 'indigo' : 'gray'}
                                    variant={done || current ? 'filled' : 'light'}
                                >
                                    {done ? '✓' : idx + 1}
                                </Badge>
                                <Text size="xs" fw={current ? 600 : 400} c={current ? 'indigo' : 'dimmed'}>
                                    {label}
                                </Text>
                                {idx < 2 && <Text size="xs" c="dimmed">→</Text>}
                            </Group>
                        );
                    })}
                </Group>

                {/* Step 0: Networks & Tokens */}
                {step === 0 && (
                    <Stack gap="md">
                        <Text size="sm" c="dimmed">
                            {t`Choose which blockchains and tokens you want to accept from your ticket buyers. You can update these anytime.`}
                        </Text>

                        <Stack gap="sm">
                            {SUPPORTED_NETWORKS.map((network) => {
                                const isSelected = selectedNetworks.includes(network.id);
                                return (
                                    <Paper
                                        key={network.id}
                                        p="md"
                                        withBorder
                                        style={{
                                            borderColor: isSelected ? '#6366f1' : undefined,
                                            backgroundColor: isSelected ? 'rgba(99, 102, 241, 0.04)' : undefined,
                                            cursor: network.id === 'evm' ? 'default' : 'pointer',
                                        }}
                                        onClick={() => toggleNetwork(network.id)}
                                    >
                                        <Group justify="space-between" align="flex-start" wrap="nowrap">
                                            <Group gap="sm" align="flex-start">
                                                <Checkbox
                                                    checked={isSelected}
                                                    onChange={() => toggleNetwork(network.id)}
                                                    disabled={network.id === 'evm'}
                                                    mt={2}
                                                />
                                                <div>
                                                    <Group gap={8}>
                                                        <Text fw={600} size="sm">
                                                            {network.name}
                                                        </Text>
                                                        {network.recommended && (
                                                            <Badge size="xs" color="indigo" variant="light">
                                                                {t`Recommended`}
                                                            </Badge>
                                                        )}
                                                    </Group>
                                                    <Text size="xs" c="dimmed" mt={2}>
                                                        {network.description}
                                                    </Text>
                                                    <Group gap={4} mt={6}>
                                                        {network.tokens.map((token) => (
                                                            <Badge key={token} size="xs" variant="outline" color="gray">
                                                                {token}
                                                            </Badge>
                                                        ))}
                                                    </Group>
                                                </div>
                                            </Group>
                                        </Group>
                                    </Paper>
                                );
                            })}
                        </Stack>

                        <Group justify="flex-end" mt="md">
                            <Button
                                rightSection={<IconArrowRight size={16} />}
                                onClick={() => setStep(1)}
                            >
                                {t`Next: Payout Wallet`}
                            </Button>
                        </Group>
                    </Stack>
                )}

                {/* Step 1: Payout Wallet */}
                {step === 1 && (
                    <Stack gap="md">
                        <div>
                            <Text fw={600} size="sm">
                                {t`Where should your sales settle?`}
                            </Text>
                            <Text size="xs" c="dimmed" mt={2}>
                                {t`Enter your self-custody cold wallet. 97.5% of ticket sales are swept here directly on-chain upon purchase.`}
                            </Text>
                        </div>

                        <Paper p="md" withBorder>
                            <Stack gap="sm">
                                <Group justify="space-between">
                                    <Text fw={500} size="sm">
                                        {t`EVM Payout Address (Ethereum / Base / Polygon)`}
                                    </Text>
                                    {hasInjectedWallet && (
                                        <Button
                                            variant="light"
                                            size="compact-xs"
                                            leftSection={<IconWallet size={14} />}
                                            loading={isConnectingWallet}
                                            onClick={handleConnectWeb3Wallet}
                                        >
                                            {t`Connect Web3 Wallet`}
                                        </Button>
                                    )}
                                </Group>

                                <TextInput
                                    placeholder="0x142e57a939aBeFb8D50Ab39A8aB58ef9572620ef"
                                    value={walletAddress}
                                    onChange={(e) => {
                                        setWalletAddress(e.currentTarget.value);
                                        setWalletError(null);
                                    }}
                                    error={walletError}
                                    leftSection={<IconWallet size={16} />}
                                />

                                <Alert variant="light" color="indigo" icon={<IconShieldCheck size={16} />}>
                                    <Text size="xs">
                                        {t`Non-custodial & safe: Monno deploys smart contract deposit routing so funds sweep straight to this address. We never ask for or hold your private keys.`}
                                    </Text>
                                </Alert>
                            </Stack>
                        </Paper>

                        {selectedNetworks.includes('tron') && (
                            <TextInput
                                label={t`Tron Payout Address (Optional)`}
                                placeholder="T..."
                                value={tronAddress}
                                onChange={(e) => setTronAddress(e.currentTarget.value)}
                            />
                        )}

                        {selectedNetworks.includes('bitcoin') && (
                            <TextInput
                                label={t`Bitcoin Payout Address (Optional)`}
                                placeholder="bc1..."
                                value={btcAddress}
                                onChange={(e) => setBtcAddress(e.currentTarget.value)}
                            />
                        )}

                        <Group justify="space-between" mt="md">
                            <Button
                                variant="default"
                                leftSection={<IconArrowLeft size={16} />}
                                onClick={() => setStep(0)}
                            >
                                {t`Back`}
                            </Button>
                            <Button
                                rightSection={<IconArrowRight size={16} />}
                                onClick={validateAndProceedToConfirm}
                            >
                                {t`Review & Activate`}
                            </Button>
                        </Group>
                    </Stack>
                )}

                {/* Step 2: Automated Infrastructure Activation */}
                {step === 2 && (
                    <Stack align="center" gap="md" py="xl">
                        <Loader size="lg" color="indigo" />
                        <Text fw={600} size="md">
                            {t`Configuring Monno Crypto Connect...`}
                        </Text>
                        <Stack gap="xs" style={{maxWidth: 360, width: '100%'}}>
                            <Group gap="xs">
                                <ThemeIcon size="xs" color="teal" radius="xl">
                                    <IconCheck size={10} />
                                </ThemeIcon>
                                <Text size="xs">{t`Updating your merchant project`}</Text>
                            </Group>
                            <Group gap="xs">
                                <ThemeIcon size="xs" color="teal" radius="xl">
                                    <IconCheck size={10} />
                                </ThemeIcon>
                                <Text size="xs">{t`Saving your payout wallet`}</Text>
                            </Group>
                            <Group gap="xs">
                                <ThemeIcon size="xs" color="teal" radius="xl">
                                    <IconCheck size={10} />
                                </ThemeIcon>
                                <Text size="xs">{t`Recording your accepted currencies`}</Text>
                            </Group>
                            <Group gap="xs">
                                <ThemeIcon size="xs" color="indigo" radius="xl">
                                    <Loader size={10} color="white" />
                                </ThemeIcon>
                                <Text size="xs">{t`Confirming with the payment gateway`}</Text>
                            </Group>
                        </Stack>
                    </Stack>
                )}

                {/* Step 3: Success & Review */}
                {step === 3 && (
                    <Stack gap="md" py="xs">
                        {gatewayConfirmed === false ? (
                            <Alert color="orange" icon={<IconAlertCircle size={18}/>} title={t`Payout wallet not confirmed yet`}>
                                <Text size="sm">
                                    {t`Your settings were saved, but the payment gateway has not confirmed your payout wallet. Finish the wallet step in the PayRam console, then reopen this setup.`}
                                </Text>
                            </Alert>
                        ) : (
                            <Alert color="teal" icon={<IconCheck size={18}/>} title={t`Crypto payments are ready`}>
                                <Text size="sm">
                                    {t`Buyers can now pay with crypto on your events. Your payout address is on file and sales settle to it on-chain.`}
                                </Text>
                            </Alert>
                        )}

                        <Paper p="md" withBorder>
                            <Stack gap="xs">
                                <Group justify="space-between">
                                    <Text size="xs" c="dimmed">{t`Payout Address`}</Text>
                                    <Badge size="sm" variant="light" color="gray" style={{fontFamily: 'monospace'}}>
                                        {walletAddress.slice(0, 10)}...{walletAddress.slice(-8)}
                                    </Badge>
                                </Group>
                                <Group justify="space-between">
                                    <Text size="xs" c="dimmed">{t`Platform Fee`}</Text>
                                    <Text size="xs" fw={500}>{t`2.5% (grossed up onto buyer)`}</Text>
                                </Group>
                                <Group justify="space-between">
                                    <Text size="xs" c="dimmed">{t`Settlement Speed`}</Text>
                                    <Text size="xs" fw={500}>{t`Instant on-chain sweep upon ticket purchase`}</Text>
                                </Group>
                            </Stack>
                        </Paper>

                        <Group justify="flex-end" mt="md">
                            <Button onClick={onClose} color="indigo">
                                {t`Done`}
                            </Button>
                        </Group>
                    </Stack>
                )}
            </Box>
        </Modal>
    );
};
