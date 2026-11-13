import {t} from "@lingui/macro";
import {Alert, Button, Radio, Stack, Text, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useParams} from "react-router";
import {useEffect} from "react";
import {Card} from "../../../../../common/Card";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {useUpdateEventSettings} from "../../../../../../mutations/useUpdateEventSettings.ts";
import {useGetEventSettings} from "../../../../../../queries/useGetEventSettings.ts";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {IconExternalLink} from "@tabler/icons-react";

export const RegistrationSettings = () => {
    const {eventId} = useParams();
    const eventSettingsQuery = useGetEventSettings(eventId);
    const updateMutation = useUpdateEventSettings();
    const form = useForm({
        initialValues: {
            ticketing_mode: 'PLATFORM' as 'PLATFORM' | 'EXTERNAL',
            external_ticket_url: '',
            get_tickets_button_text: '',
        },
        validate: {
            external_ticket_url: (value, values) => {
                if (values.ticketing_mode !== 'EXTERNAL') {
                    return null;
                }

                if (!value) {
                    return t`An external ticket URL is required when tickets are sold externally.`;
                }

                try {
                    const url = new URL(value);
                    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
                        return t`The URL must start with http:// or https://`;
                    }
                } catch {
                    return t`Please enter a valid URL.`;
                }

                return null;
            },
        },
    });
    const formErrorHandle = useFormErrorResponseHandler();

    useEffect(() => {
        if (eventSettingsQuery?.isFetched && eventSettingsQuery?.data) {
            form.setValues({
                ticketing_mode: eventSettingsQuery.data.ticketing_mode === 'EXTERNAL' ? 'EXTERNAL' : 'PLATFORM',
                external_ticket_url: eventSettingsQuery.data.external_ticket_url ?? '',
                get_tickets_button_text: eventSettingsQuery.data.get_tickets_button_text ?? '',
            });
        }
    }, [eventSettingsQuery.isFetched]);

    const isExternal = form.values.ticketing_mode === 'EXTERNAL';
    const buttonLabel = form.values.get_tickets_button_text || t`Get Tickets`;

    const handleSubmit = (values: typeof form.values) => {
        updateMutation.mutate({
            eventSettings: {
                ticketing_mode: values.ticketing_mode,
                external_ticket_url: values.ticketing_mode === 'EXTERNAL' ? values.external_ticket_url : '',
                get_tickets_button_text: values.get_tickets_button_text,
            },
            eventId: eventId,
        }, {
            onSuccess: () => {
                showSuccess(t`Successfully Updated Settings`);
            },
            onError: (error) => {
                formErrorHandle(form, error);
            }
        });
    };

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Registration`}
                description={t`Choose where customers get their tickets: right here, or on your own registration page.`}
            />
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending}>
                    <Radio.Group
                        {...form.getInputProps('ticketing_mode')}
                        label={t`Where are tickets sold?`}
                        withAsterisk
                    >
                        <Stack mt="xs" gap="xs">
                            <Radio
                                value="PLATFORM"
                                label={t`Sell tickets here`}
                                description={t`Customers pick a ticket and pay on monno.`}
                            />
                            <Radio
                                value="EXTERNAL"
                                label={t`Link to my own registration`}
                                description={t`The buy button sends customers to your own ticket page.`}
                            />
                        </Stack>
                    </Radio.Group>

                    {isExternal && (
                        <>
                            <TextInput
                                {...form.getInputProps('external_ticket_url')}
                                label={t`External ticket URL`}
                                description={t`Where customers should go to register or buy tickets.`}
                                placeholder="https://tickets.example.com/my-event"
                                mt="md"
                                withAsterisk
                            />

                            <Alert color="yellow" mt="md" icon={<IconExternalLink size={16}/>}>
                                {t`While this is on, tickets and prices you have already set up will not be sold on monno for this event. Switch back to selling here at any time.`}
                            </Alert>
                        </>
                    )}

                    <TextInput
                        {...form.getInputProps('get_tickets_button_text')}
                        label={t`Button label`}
                        description={t`Shown on your event page's ticket button. Leave empty for the default.`}
                        placeholder={t`Get Tickets`}
                        maxLength={100}
                        mt="md"
                    />

                    <Stack mt="md" gap={6}>
                        <Text size="xs" c="dimmed">{t`Preview`}</Text>
                        {/* A real-looking button, not a disabled one: the label is what
                            is being previewed, and the disabled palette made it almost
                            unreadable against its own background. `component="span"`
                            keeps it inert, so it is never a tab stop either. */}
                        <Button component="span" w="fit-content">
                            {buttonLabel}
                        </Button>
                    </Stack>

                    <Button loading={updateMutation.isPending} type="submit" mt="xl">
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
};
