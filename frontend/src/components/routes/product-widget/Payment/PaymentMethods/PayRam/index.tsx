import {useParams} from "react-router";
import {useEffect} from "react";
import {Alert, Group, Skeleton, Stack, Text} from "@mantine/core";
import {IconClock, IconInfoCircle} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {useCreatePayRamPayment} from "../../../../../../queries/useCreatePayRamPayment.ts";
import {Card} from "../../../../../common/Card";
import {formatCurrency} from "../../../../../../utilites/currency.ts";

type ApiErrorResponse = {
    response?: {data?: {message?: string}};
};

interface PayRamPaymentMethodProps {
    enabled: boolean;
    setSubmitHandler: (submitHandler: () => () => Promise<void>) => void;
}

/**
 * Hosted crypto checkout: we open a PayRam session up front so the buyer sees
 * the USD equivalent of their local-currency total (and the fee line) before
 * leaving for the gateway.
 */
export const PayRamPaymentMethod = ({enabled, setSubmitHandler}: PayRamPaymentMethodProps) => {
    const {eventId, orderShortId} = useParams();
    const {data, isFetched, error} = useCreatePayRamPayment(eventId, orderShortId);

    useEffect(() => {
        if (!data?.url) {
            return;
        }

        const redirectToCheckout = async () => {
            window.location.assign(data.url);
        };

        setSubmitHandler(() => redirectToCheckout);
    }, [data, setSubmitHandler]);

    if (!enabled) {
        return (
            <Card>
                <Text>{t`Crypto payments are not available for this event.`}</Text>
            </Card>
        );
    }

    if (error) {
        const message = (error as ApiErrorResponse)?.response?.data?.message
            ?? t`Crypto checkout could not be started. Please try again or pick another payment method.`;

        return (
            <Card>
                <Alert color="red" icon={<IconInfoCircle size={16}/>} title={t`Payment unavailable`}>
                    {message}
                </Alert>
            </Card>
        );
    }

    if (!isFetched || !data) {
        return (
            <Card>
                <Stack gap="sm">
                    <Skeleton height={18} width="60%"/>
                    <Skeleton height={14} width="40%"/>
                </Stack>
            </Card>
        );
    }

    const expiresAt = new Date(data.expires_at);

    return (
        <Card>
            <Stack gap="md">
                <div>
                    <Text fw={600} mb={4}>{t`Pay with crypto`}</Text>
                    <Text size="sm" c="dimmed">
                        {t`You'll choose the coin and network on the next screen (USDT or USDC). Your tickets are confirmed automatically once the payment confirms.`}
                    </Text>
                </div>

                <Stack gap={4}>
                    <Group justify="space-between">
                        <Text size="sm">{formatCurrency(data.order_amount, data.order_currency)}</Text>
                        <Text size="sm" c="dimmed">≈ {formatCurrency(data.ticket_amount_in_usd, 'USD')}</Text>
                    </Group>

                    {data.platform_fee_usd > 0 && (
                        <Group justify="space-between">
                            <Text size="sm" c="dimmed">{t`Payment processing`}</Text>
                            <Text size="sm" c="dimmed">
                                {formatCurrency(data.platform_fee_usd, 'USD')}
                            </Text>
                        </Group>
                    )}

                    <Group justify="space-between">
                        <Text size="sm" fw={600}>{t`Total due`}</Text>
                        <Text size="sm" fw={600}>{formatCurrency(data.amount_in_usd, 'USD')}</Text>
                    </Group>
                </Stack>

                <Alert variant="light" color="gray" icon={<IconClock size={16}/>}>
                    {t`Your checkout is held for you until`}{' '}
                    {expiresAt.toLocaleString()}
                </Alert>
            </Stack>
        </Card>
    );
}
