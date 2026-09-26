import {useEffect} from "react";
import {useParams} from "react-router";
import {useForm} from "@mantine/form";
import {ActionIcon, Button, Group, Stack, Text, TextInput} from "@mantine/core";
import {IconArrowDown, IconArrowUp, IconPlus, IconTrash} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {Card} from "../../../../../common/Card";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {useGetEvent} from "../../../../../../queries/useGetEvent.ts";
import {useUpdateEvent} from "../../../../../../mutations/useUpdateEvent.ts";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {AgendaItem, Event} from "../../../../../../types.ts";

/**
 * The run-of-show list rendered on the public event page. It lives on the
 * event (jsonb), so saving goes through PUT /events/:id — which still requires
 * title/category/dates, hence the base payload built from the loaded event.
 */
export const AgendaSettings = () => {
    const {eventId} = useParams();
    const eventQuery = useGetEvent(eventId);
    const updateMutation = useUpdateEvent();
    const formErrorHandle = useFormErrorResponseHandler();

    const form = useForm<{ agenda: AgendaItem[] }>({
        initialValues: {agenda: []},
    });

    useEffect(() => {
        if (eventQuery?.data) {
            form.setValues({agenda: eventQuery.data.agenda ?? []});
        }
    }, [eventQuery.isFetched]);

    const updateRow = (index: number, patch: Partial<AgendaItem>) => {
        const next = form.values.agenda.map((row, i) => (i === index ? {...row, ...patch} : row));
        form.setValues({agenda: next});
    };

    const move = (index: number, delta: number) => {
        const target = index + delta;
        if (target < 0 || target >= form.values.agenda.length) return;
        const next = [...form.values.agenda];
        [next[index], next[target]] = [next[target], next[index]];
        form.setValues({agenda: next});
    };

    const remove = (index: number) => {
        form.setValues({agenda: form.values.agenda.filter((_, i) => i !== index)});
    };

    const handleSubmit = (values: { agenda: AgendaItem[] }) => {
        const event: Event = eventQuery.data!;
        updateMutation.mutate({
            eventId: eventId,
            eventData: {
                title: event.title,
                category: event.category,
                start_date: event.start_date,
                end_date: event.end_date,
                agenda: values.agenda,
            },
        }, {
            onSuccess: () => showSuccess(t`Successfully Updated Agenda`),
            onError: (error) => formErrorHandle(form, error),
        });
    };

    const rows = form.values.agenda;

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Agenda`}
                description={t`The running order shown on your public event page. Drag-free ordering: use the arrows.`}
            />
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={eventQuery.isLoading || updateMutation.isPending}>
                    {rows.length === 0 && (
                        <Text c="dimmed" size="sm" mb="md">
                            {t`No agenda items yet. Add the first one below.`}
                        </Text>
                    )}

                    <Stack gap="md">
                        {rows.map((row, index) => (
                            <div key={index}>
                                <Group align="flex-end" gap="xs" wrap="nowrap">
                                    <TextInput
                                        label={index === 0 ? t`Time` : undefined}
                                        placeholder={t`19:45`}
                                        value={row.time ?? ''}
                                        style={{width: 110, flexShrink: 0}}
                                        onChange={(e) => updateRow(index, {time: e.currentTarget.value})}
                                    />
                                    <TextInput
                                        label={index === 0 ? t`Title` : undefined}
                                        placeholder={t`Basil Wren`}
                                        required
                                        value={row.title}
                                        style={{flex: 1, minWidth: 0}}
                                        onChange={(e) => updateRow(index, {title: e.currentTarget.value})}
                                    />
                                    <TextInput
                                        label={index === 0 ? t`Detail` : undefined}
                                        placeholder={t`Support act`}
                                        value={row.detail ?? ''}
                                        style={{flex: 1.4, minWidth: 0}}
                                        onChange={(e) => updateRow(index, {detail: e.currentTarget.value})}
                                    />
                                    <Group gap={4} wrap="nowrap">
                                        <ActionIcon variant="default" aria-label={t`Move up`}
                                                   disabled={index === 0} onClick={() => move(index, -1)}>
                                            <IconArrowDown size={16}/>
                                        </ActionIcon>
                                        <ActionIcon variant="default" aria-label={t`Move down`}
                                                   disabled={index === rows.length - 1} onClick={() => move(index, 1)}>
                                            <IconArrowUp size={16}/>
                                        </ActionIcon>
                                        <ActionIcon variant="filled" color="red" aria-label={t`Remove`}
                                                   onClick={() => remove(index)}>
                                            <IconTrash size={16}/>
                                        </ActionIcon>
                                    </Group>
                                </Group>
                            </div>
                        ))}
                    </Stack>

                    <Button
                        variant="light"
                        leftSection={<IconPlus size={16}/>}
                        mt="md"
                        onClick={() => form.setValues({agenda: [...rows, {time: '', title: '', detail: ''}]})}
                    >
                        {t`Add agenda item`}
                    </Button>

                    <Button loading={updateMutation.isPending} type="submit" mt="xl">
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
};
