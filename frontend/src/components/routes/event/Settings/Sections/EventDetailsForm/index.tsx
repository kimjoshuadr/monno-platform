import {t} from "@lingui/macro";
import {Button, Select, Switch, TextInput} from "@mantine/core";
import {IconCalendarRepeat} from "@tabler/icons-react";
import {useForm} from "@mantine/form";
import {NavLink, useParams} from "react-router";
import {useGetEvent} from "../../../../../../queries/useGetEvent.ts";
import {useEffect} from "react";
import {useUpdateEvent} from "../../../../../../mutations/useUpdateEvent.ts";
import {Event, EventType} from "../../../../../../types.ts";
import {InputGroup} from "../../../../../common/InputGroup";
import {Card} from "../../../../../common/Card";
import {Callout} from "../../../../../common/Callout";
import {Editor} from "../../../../../common/Editor";
import {utcToTz} from "../../../../../../utilites/dates.ts";
import {showSuccess} from "../../../../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../../../../hooks/useFormErrorResponseHandler.tsx";
import {currenciesMap} from "../../../../../../../data/currencies.ts";
import {timezones} from "../../../../../../../data/timezones.ts";
import {HeadingWithDescription} from "../../../../../common/Card/CardHeading";
import {getEventCategories} from "../../../../../../constants/eventCategories.ts";

export const EventDetailsForm = () => {
    const {eventId} = useParams();
    const eventQuery = useGetEvent(eventId);
    const updateMutation = useUpdateEvent();
    const isRecurring = eventQuery.data?.type === EventType.RECURRING;
    const form = useForm({
        initialValues: {
            title: '',
            description: '',
            start_date: '',
            end_date: '',
            timezone: '',
            currency: '',
            category: '',
            tagline: '',
            featured: false,
            image_alt: '',
        }
    });
    const formErrorHandle = useFormErrorResponseHandler();

    useEffect(() => {
        if (eventQuery?.data) {
            form.setValues({
                title: eventQuery.data.title,
                description: eventQuery.data.description,
                start_date: utcToTz(eventQuery.data.start_date, eventQuery.data.timezone),
                end_date: utcToTz(eventQuery.data.end_date, eventQuery.data.timezone),
                timezone: eventQuery.data.timezone,
                currency: eventQuery.data.currency,
                category: eventQuery.data.category,
                tagline: eventQuery.data.tagline || '',
                featured: !!eventQuery.data.featured,
                image_alt: eventQuery.data.image_alt || '',
            });
        }
    }, [eventQuery.isFetched]);

    const handleSubmit = (values: Partial<Event>) => {
        updateMutation.mutate({
            eventData: values,
            eventId: eventId,
        }, {
            onSuccess: () => {
                showSuccess(t`Successfully Updated Event`);
            },
            onError: (error) => {
                formErrorHandle(form, error);
            }
        });
    }

    return (
        <Card>
            <HeadingWithDescription
                heading={t`Event Details`}
                description={isRecurring
                    ? t`Update event name and description`
                    : t`Update event name, description and dates`}
            />
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <fieldset disabled={eventQuery.isLoading || updateMutation.isPending}>
                    <TextInput
                        {...form.getInputProps('title')}
                        label={t`Name`}
                        placeholder={t`Summer Music Festival ${new Date().getFullYear()}`}
                        required
                    />

                    <TextInput
                        {...form.getInputProps('tagline')}
                        label={t`Tagline`}
                        description={t`One short line shown under the title on the public event page.`}
                        placeholder={t`Three rooms, one long night`}
                        maxLength={200}
                    />

                    <TextInput
                        {...form.getInputProps('image_alt')}
                        label={t`Cover image alt text`}
                        description={t`Describes the cover image for screen readers and search engines.`}
                        placeholder={t`Crowd under red light`}
                        maxLength={255}
                    />

                    <Switch
                        {...form.getInputProps('featured', {type: 'checkbox'})}
                        label={t`Featured event`}
                        description={t`Featured events are highlighted in rails and on the home page.`}
                    />

                    <Select
                        {...form.getInputProps('category')}
                        label={t`Category`}
                        placeholder={t`Select a category`}
                        data={getEventCategories().map((category) => ({
                            value: category.id,
                            label: `${category.emoji} ${category.name}`,
                        }))}
                        searchable
                        clearable
                    />

                    <Editor
                        label={t`Description`}
                        value={form.values.description || ''}
                        onChange={(value) => form.setFieldValue('description', value)}
                        error={form.errors?.description as string}
                    />

                    {isRecurring ? (
                        <Callout variant="info" title={t`Dates are managed per occurrence`}>
                            {t`This event's dates and times are set on the occurrence schedule.`}
                            <div style={{marginTop: '0.75rem'}}>
                                <Button
                                    component={NavLink}
                                    to={'/manage/event/' + eventId + '/occurrences'}
                                    leftSection={<IconCalendarRepeat size={16}/>}
                                    variant="light"
                                >
                                    {t`Manage schedule`}
                                </Button>
                            </div>
                        </Callout>
                    ) : (
                        <InputGroup>
                            <TextInput type={'datetime-local'}
                                       {...form.getInputProps('start_date')}
                                       label={t`Start Date`}
                                       required
                            />
                            <TextInput type={'datetime-local'}
                                       {...form.getInputProps('end_date')}
                                       label={t`End Date`}
                            />
                        </InputGroup>
                    )}
                    <InputGroup>
                        <Select
                            searchable
                            data={currenciesMap}
                            {...form.getInputProps('currency')}
                            label={t`Currency`}
                            placeholder={t`EUR`}
                            description={t`The currency used for this event's ticket prices.`}
                        />

                        <Select
                            searchable
                            data={timezones}
                            {...form.getInputProps('timezone')}
                            label={t`Timezone`}
                            placeholder={t`UTC`}
                            description={t`The timezone used for this event's dates and times.`}
                        />
                    </InputGroup>
                    <Button loading={updateMutation.isPending} type={'submit'}>
                        {t`Save`}
                    </Button>
                </fieldset>
            </form>
        </Card>
    );
}
