import {useGetMe} from "../../../queries/useGetMe.ts";
import {useGetOrganizers} from "../../../queries/useGetOrganizers.ts";
import {t, Trans} from "@lingui/macro";
import {Card} from "../../common/Card";
import {Button, Center, Container, PinInput, Select, Stack, Switch, Text, TextInput} from "@mantine/core";
import classes from "./Welcome.module.scss";
import {useForm} from "@mantine/form";
import {useDebouncedValue, useMediaQuery} from "@mantine/hooks";
import {Event, EventType, IdParam} from "../../../types.ts";
import {useCreateEvent} from "../../../mutations/useCreateEvent.ts";
import {NavLink, Navigate, useNavigate} from "react-router";
import {useEffect, useRef, useState} from "react";
import {useGetEvents} from "../../../queries/useGetEvents.ts";
import {LoadingMask} from "../../common/LoadingMask";
import {LoadingContainer} from "../../common/LoadingContainer";
import {OrganizerCreateForm} from "../../forms/OrganizerForm";
import {useConfirmEmailWithCode} from "../../../mutations/useConfirmEmailWithCode.ts";
import {useResendEmailConfirmation} from "../../../mutations/useResendEmailConfirmation.ts";
import {IconCalendarRepeat, IconClock, IconMailCheck, IconSparkles} from "@tabler/icons-react";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {DateTimePicker} from "@mantine/dates";
import dayjs from "dayjs";
import {getEventCategories} from "../../../constants/eventCategories.ts";
import {Callout} from "../../common/Callout";
import {getConfig} from "../../../utilites/config.ts";
import {trackEvent, AnalyticsEvents} from "../../../utilites/analytics.ts";
import {getDateTimePickerFormat} from "../../../utilites/dates.ts";

export const CreateOrganizer = ({progressInfo}: {
    progressInfo?: { currentStep: number, totalSteps: number, progressPercentage: number }
}) => {
    return (
        <div className={classes.stepContainer}>
            <div className={classes.stepHeader}>
                {progressInfo && (
                    <div className={classes.progressContainer}>
                        <div className={classes.progressBar}>
                            <div className={classes.progressFill}
                                 style={{width: `${progressInfo.progressPercentage}%`}}></div>
                        </div>
                    </div>
                )}
                <h2 className={classes.stepTitle}>
                    {t`Set up your organization`}
                </h2>
                <p className={classes.stepDescription}>
                    {t`Tell us about your organization. This information will be displayed on your event pages.`}
                </p>
            </div>
            <div className={classes.stepContent}>
                <OrganizerCreateForm/>
            </div>
        </div>
    );
}

/**
 * The 5-digit code step. Mounts in two places: the onboarding wizard (with its
 * progress bar) and the standalone /verify-email screen an organizer who logs in
 * unverified is parked on. Both drive the same endpoint, so the behaviour lives
 * here once.
 */
export const ConfirmVerificationPin = ({progressInfo, onVerified}: {
    progressInfo?: { currentStep: number, totalSteps: number, progressPercentage: number },
    onVerified?: () => void,
}) => {
    const {data: userData} = useGetMe();
    const confirmEmailMutation = useConfirmEmailWithCode();
    const resendMutation = useResendEmailConfirmation();
    const [resendCooldown, setResendCooldown] = useState(0);
    const [completedPin, setCompletedPin] = useState('');
    const isMobile = useMediaQuery('(max-width: 768px)');
    // The API reports the code's real TTL, so the copy quotes a number the
    // cache actually honours instead of a promise that was never kept.
    const codeTtlMinutes = userData?.email_verification_ttl_minutes ?? 30;

    const form = useForm({
        initialValues: {
            pin: '',
        },
        validate: {
            pin: (value) => value.length !== 5 ? t`Please enter the 5-digit code` : null,
        }
    });

    // Debounce the completed pin value
    const [debouncedPin] = useDebouncedValue(completedPin, 800);

    // Auto-submit when debounced pin is complete
    useEffect(() => {
        if (debouncedPin.length === 5 && !confirmEmailMutation.isPending) {
            handleSubmit({pin: debouncedPin});
        }
    }, [debouncedPin]);

    useEffect(() => {
        if (resendCooldown > 0) {
            const timer = setTimeout(() => setResendCooldown(resendCooldown - 1), 1000);
            return () => clearTimeout(timer);
        }
    }, [resendCooldown]);

    const handleSubmit = (values: { pin: string }) => {
        confirmEmailMutation.mutate({
                userId: userData?.id || '',
                code: values.pin,
            }, {
                onSuccess: () => {
                    showSuccess(t`Email verified successfully!`);
                    form.reset();
                    setCompletedPin('');
                    // Tracking is the caller's call: onboarding completed a signup,
                    // the /verify-email screen is a returning organizer logging in
                    // and must not fire the signup conversion again.
                    onVerified?.();
                },
                onError: (error: any) => {
                    showError(error.response?.data?.message || t`Failed to verify email`);
                    // Clear the pin on error so user can try again
                    form.reset();
                    setCompletedPin('');
                }
            }
        );
    }

    const handleResend = async () => {
        if (!userData?.id) return;

        try {
            await resendMutation.mutateAsync({userId: userData.id});
            showSuccess(t`A new verification code has been sent to your email`);
            setResendCooldown(30);
            form.reset();
        } catch (error: any) {
            if (error?.response?.status === 429) {
                const remainingSeconds = error.response.data?.message?.match(/\d+/)?.[0] || 30;
                setResendCooldown(parseInt(remainingSeconds));
                showError(error.response.data?.message || t`Please wait before requesting another code`);
            } else {
                showError(t`Failed to resend verification code`);
            }
        }
    }

    return (
        <div className={classes.stepContainer}>
            <div className={classes.stepHeader}>
                {progressInfo && (
                    <div className={classes.progressContainer}>
                        <div className={classes.progressBar}>
                            <div className={classes.progressFill}
                                 style={{width: `${progressInfo.progressPercentage}%`}}></div>
                        </div>
                    </div>
                )}
                <h2 className={classes.stepTitle}>
                    {t`Check your email`}
                </h2>
                <p className={classes.stepDescription}>
                    {t`We've sent a 5-digit verification code to:`}
                </p>
                <div className={classes.emailDisplay}>
                    {userData?.email}
                </div>
            </div>

            <div className={classes.stepContent}>
                <form onSubmit={form.onSubmit(handleSubmit)}>
                    <Stack gap={32}>
                        <Center>
                            <PinInput
                                {...form.getInputProps('pin')}
                                inputMode={'numeric'}
                                aria-label={t`Verification code`}
                                size={isMobile ? 'sm' : 'xl'}
                                length={5}
                                placeholder="•"
                                type="number"
                                disabled={confirmEmailMutation.isPending}
                                error={!!form.errors.pin}
                                className={classes.pinInput}
                                gap={isMobile ? 8 : "sm"}
                                onChange={(value) => {
                                    form.setFieldValue('pin', value);
                                    if (value.length === 5) {
                                        setCompletedPin(value);
                                    } else {
                                        setCompletedPin('');
                                    }
                                }}
                            />
                        </Center>

                        {/* Mantine's `error` prop only toggles the error styles on
                            the inputs; without this line the server's reason —
                            expired, wrong, budget spent — never reaches the user. */}
                        {form.errors.pin && (
                            <Text size="sm" c="red" ta="center" role="alert" className={classes.pinError}>
                                {form.errors.pin}
                            </Text>
                        )}

                        <Button
                            type={'submit'}
                            fullWidth
                            size="lg"
                            loading={confirmEmailMutation.isPending}
                            leftSection={<IconMailCheck size={20}/>}
                            className={classes.primaryButton}
                        >
                            {confirmEmailMutation.isPending ? t`Verifying...` : t`Verify Email`}
                        </Button>

                        <Center>
                            <Stack gap="xs" align="center">
                                <Text size="sm" c="dimmed">
                                    {t`Didn't receive the code?`}
                                </Text>
                                <Button
                                    variant="subtle"
                                    size="sm"
                                    onClick={handleResend}
                                    disabled={resendCooldown > 0 || resendMutation.isPending}
                                    loading={resendMutation.isPending}
                                    leftSection={resendCooldown > 0 ? <IconClock size={16}/> : null}
                                >
                                    {resendCooldown > 0
                                        ? t`Resend in ${resendCooldown}s`
                                        : t`Resend code`}
                                </Button>
                            </Stack>
                        </Center>

                        <Text size="xs" c="dimmed" ta="center" className={classes.helpText}>
                            {t`The code will expire in ${codeTtlMinutes} minutes. Check your spam folder if you don't see the email.`}
                        </Text>
                    </Stack>
                </form>
            </div>
        </div>
    );
}

interface CreateEventFormValues {
    title: string;
    type: EventType;
    start_date: string | Date | null;
    end_date: string | Date | null;
    category: string;
    organizer_id?: IdParam;
}

export const CreateEvent = ({progressInfo}: {
    progressInfo?: { currentStep: number, totalSteps: number, progressPercentage: number }
}) => {
    const [selectedCategory, setSelectedCategory] = useState<string | null>(null);
    const form = useForm<CreateEventFormValues>({
        initialValues: {
            title: '',
            type: EventType.SINGLE,
            start_date: dayjs().add(1, 'day').hour(19).minute(0).second(0).toDate(),
            end_date: dayjs().add(1, 'day').hour(21).minute(0).second(0).toDate(),
            category: '',
        },
        validate: {
            title: (value) => !value ? t`Event name is required` : null,
            start_date: (value, values) => {
                if (values.type === EventType.RECURRING) return null;
                return !value ? t`Start date is required` : null;
            },
            end_date: (value, values) => {
                if (values.type === EventType.RECURRING) return null;
                if (value && values.start_date && dayjs(value).isBefore(dayjs(values.start_date))) {
                    return t`End date must be after start date`;
                }
            },
        }
    });
    const eventMutation = useCreateEvent();
    const navigate = useNavigate();
    const {data: organizers, isFetched: organizersFetched} = useGetOrganizers();
    const {data: events, isFetched: eventsFetched} = useGetEvents({
        pageNumber: 1,
    });

    const isRecurring = form.values.type === EventType.RECURRING;

    const handleRecurringToggle = (checked: boolean) => {
        form.setFieldValue('type', checked ? EventType.RECURRING : EventType.SINGLE);
    };

    const handleSubmit = (values: CreateEventFormValues) => {
        const recurring = values.type === EventType.RECURRING;
        const submitData: Partial<Event> = {
            ...values,
            start_date: !recurring && values.start_date ? dayjs(values.start_date).toISOString() : undefined,
            end_date: !recurring && values.end_date ? dayjs(values.end_date).toISOString() : undefined,
        };

        eventMutation.mutate({
            eventData: submitData,
        }, {
            onSuccess: (result) => {
                trackEvent(AnalyticsEvents.FIRST_EVENT_CREATED);
                navigate(`/manage/event/${result.data.id}/dashboard?new_event=true`);
            }
        });
    }

    useEffect(() => {
        if (organizersFetched) {
            const organizerName = organizers?.data?.[0].name
            form.setFieldValue('organizer_id', organizers?.data?.[0].id);
            form.setFieldValue('title', t`${organizerName}'s first event`);
        }
    }, [organizersFetched]);

    const handleCategorySelect = (categoryId: string) => {
        setSelectedCategory(categoryId);
        form.setFieldValue('category', categoryId);

        // Add haptic feedback on mobile
        if ('vibrate' in navigator) {
            navigator.vibrate(50);
        }
    };

    useEffect(() => {
        if (eventsFetched && events && events.data.length > 0) {
            navigate(`/manage/events`);
        }
    }, [eventsFetched]);

    return (
        <LoadingContainer>
            <div className={classes.stepContainer}>
                <div className={classes.stepHeader}>
                    {progressInfo && (
                        <div className={classes.progressContainer}>
                            <div className={classes.progressBar}>
                                <div className={classes.progressFill}
                                     style={{width: `${progressInfo.progressPercentage}%`}}></div>
                            </div>
                        </div>
                    )}
                    <h2 className={classes.stepTitle}>
                        {t`Create your first event`}
                    </h2>
                </div>

                <div className={classes.stepContent}>
                    <form onSubmit={form.onSubmit(handleSubmit)}>
                        <Stack gap={24}>
                            {/* Event Category */}
                            <div>
                                <Text size="lg" fw={600} mb="lg">{t`What type of event?`}</Text>

                                {/* Desktop Grid */}
                                <div className={classes.categoryGrid}>
                                    {getEventCategories().map((category) => (
                                        <button
                                            key={category.id}
                                            type="button"
                                            className={`${classes.categoryCard} ${
                                                selectedCategory === category.id ? classes.categoryCardSelected : ''
                                            }`}
                                            onClick={() => handleCategorySelect(category.id)}
                                            disabled={eventMutation.isPending}
                                        >
                                            <div className={classes.categoryEmoji}>{category.emoji}</div>
                                            <div className={classes.categoryText}>{category.name}</div>
                                        </button>
                                    ))}
                                </div>

                                {/* Mobile Dropdown */}
                                <div className={classes.categoryDropdown}>
                                    <Select
                                        value={selectedCategory}
                                        onChange={(value) => handleCategorySelect(value || '')}
                                        data={getEventCategories().map((category) => ({
                                            value: category.id,
                                            label: `${category.emoji} ${category.name}`,
                                        }))}
                                        placeholder={t`Select event category`}
                                        size="lg"
                                        required
                                        disabled={eventMutation.isPending}
                                    />
                                </div>
                            </div>

                            {/* Event Name */}
                            <div>
                                <TextInput
                                    {...form.getInputProps('title')}
                                    label={t`Event name`}
                                    placeholder={t`Summer Music Festival 2025`}
                                    size="lg"
                                    required
                                    disabled={eventMutation.isPending}
                                />
                            </div>

                            {/* Date & Time */}
                            <div className={classes.dateSection}>
                                <Switch
                                    checked={isRecurring}
                                    onChange={(event) => handleRecurringToggle(event.currentTarget.checked)}
                                    disabled={eventMutation.isPending}
                                    size="md"
                                    labelPosition="left"
                                    classNames={{
                                        root: classes.recurringToggle,
                                        body: classes.recurringToggleBody,
                                        labelWrapper: classes.recurringToggleLabelWrapper,
                                    }}
                                    label={
                                        <span className={classes.recurringToggleLabel}>
                                            <IconCalendarRepeat size={18} className={classes.recurringToggleIcon}/>
                                            <span className={classes.recurringToggleText}>
                                                <span className={classes.recurringToggleTitle}>
                                                    {t`This is a recurring event`}
                                                </span>
                                                <span className={classes.recurringToggleHint}>
                                                    {t`It happens on more than one date`}
                                                </span>
                                            </span>
                                        </span>
                                    }
                                />

                                {isRecurring ? (
                                    <Callout
                                        variant="info"
                                        icon={<IconCalendarRepeat size={20}/>}
                                        title={t`Set up your schedule in the next steps`}
                                        className={classes.recurringCallout}
                                    >
                                        {t`After your event is created, you can choose how often it repeats from the dashboard.`}
                                    </Callout>
                                ) : (
                                    <div className={classes.dateTimeGrid}>
                                        <DateTimePicker
                                            {...form.getInputProps('start_date')}
                                            label={t`Start date & time`}
                                            placeholder={t`Select start time`}
                                            valueFormat={getDateTimePickerFormat()}
                                            size="lg"
                                            required
                                            dropdownType="modal"
                                            timePickerProps={{
                                                format: '12h',
                                                withDropdown: true,
                                            }}
                                            onChange={(value) => {
                                                form.setFieldValue('start_date', value);
                                                if (form.values.end_date && value && dayjs(form.values.end_date).isBefore(dayjs(value))) {
                                                    form.setFieldValue('end_date', dayjs(value).add(2, 'hours').toDate());
                                                }
                                            }}
                                            disabled={eventMutation.isPending}
                                        />

                                        <DateTimePicker
                                            {...form.getInputProps('end_date')}
                                            label={t`End time (optional)`}
                                            placeholder={t`Select end time`}
                                            valueFormat={getDateTimePickerFormat()}
                                            size="lg"
                                            dropdownType="modal"
                                            timePickerProps={{
                                                format: '12h',
                                                withDropdown: true,
                                            }}
                                            minDate={form.values.start_date ?? undefined}
                                            date={form.values.start_date ?? undefined}
                                            disabled={eventMutation.isPending}
                                        />
                                    </div>
                                )}
                            </div>
                        </Stack>
                        <Button
                            type={'submit'}
                            fullWidth
                            size="lg"
                            loading={eventMutation.isPending}
                            leftSection={eventMutation.isPending ? null : <IconSparkles size={20}/>}
                            className={classes.primaryButton}
                            disabled={eventMutation.isPending || !selectedCategory}
                            aria-label={eventMutation.isPending ? t`Creating your event, please wait` : t`Continue to next step`}
                        >
                            {eventMutation.isPending ? t`Creating Event...` : t`Continue Setup`}
                        </Button>
                    </form>
                </div>
            </div>
        </LoadingContainer>
    );
}

// Helper function to get progress information
const getProgressInfo = (requiresVerification: boolean, currentStep: 'verification' | 'organizer' | 'event') => {
    const totalSteps = requiresVerification ? 3 : 2;
    let currentStepNumber = 1;

    if (requiresVerification) {
        if (currentStep === 'verification') currentStepNumber = 1;
        else if (currentStep === 'organizer') currentStepNumber = 2;
        else if (currentStep === 'event') currentStepNumber = 3;
    } else {
        if (currentStep === 'organizer') currentStepNumber = 1;
        else if (currentStep === 'event') currentStepNumber = 2;
    }

    const progressPercentage = (currentStepNumber / totalSteps) * 100;

    return {
        currentStep: currentStepNumber,
        totalSteps,
        progressPercentage
    };
};

const Welcome = () => {
    const me = useGetMe();
    const userData = me.data;

    // Decided before the queries below, because while an email is unconfirmed
    // both endpoints answer 403 — firing them here would run the verify screen
    // straight into the lock (and, via the API client, out to the login page).
    const requiresVerification = !!(userData
        && userData.enforce_email_confirmation_during_registration
        && !userData.is_email_verified);

    const organizersQuery = useGetOrganizers({enabled: me.isFetched && !requiresVerification});
    const organizers = organizersQuery?.data?.data;
    const organizerExists = organizersQuery.isFetched && Number(organizers?.length) > 0;
    const firstOrganizerId = organizers?.[0]?.id;
    const hasTrackedSignup = useRef(false);

    // Tells "first time" apart from "already set up": an account that already
    // has an organizer AND events has nothing to set up on this screen.
    // Same query/args as CreateEvent below, so React Query dedupes it.
    const eventsQuery = useGetEvents({pageNumber: 1}, {enabled: me.isFetched && !requiresVerification});
    const events = eventsQuery?.data?.data;
    const hasEvents = eventsQuery.isFetched && Number(events?.length) > 0;

    useEffect(() => {
        if (!userData || hasTrackedSignup.current) {
            return;
        }
        // Only track if email verification was NEVER required for this account.
        // Accounts that had to verify are tracked when ConfirmVerificationPin
        // calls back through onVerified above.
        if (!userData.enforce_email_confirmation_during_registration) {
            hasTrackedSignup.current = true;
            trackEvent(AnalyticsEvents.SIGNUP_COMPLETED);
        }
    }, [userData]);

    // Already set up — onboarding is only for the first time. Leave for the
    // dashboard instead of bouncing /manage/events -> /welcome -> /manage/events.
    if (!requiresVerification && organizerExists && hasEvents) {
        return <Navigate
            replace
            to={firstOrganizerId ? `/manage/organizer/${firstOrganizerId}` : '/manage/events'}
        />;
    }

    // Still resolving whether this is a first-time setup: don't flash
    // "Set up your organization" at an account that already has one.
    if (!requiresVerification && (!organizersQuery.isFetched || (organizerExists && !eventsQuery.isFetched))) {
        return <LoadingMask/>;
    }

    return (
        <div className={classes.welcomeContainer}>
            <Container size="sm" className={classes.welcomeContent}>
                <div className={classes.welcomeHeader}>
                    <div className={classes.logo}>
                        <img src={getConfig("VITE_APP_LOGO_LIGHT", "/logos/monno-text-dark.svg")} alt={`${getConfig("VITE_APP_NAME", "monno")} logo`} className={classes.logo}/>
                    </div>
                    <h1 className={classes.welcomeTitle}>
                        <Trans>
                            Welcome to {getConfig("VITE_APP_NAME", "monno")}, {userData?.first_name} 👋
                        </Trans>
                    </h1>
                </div>

                <Card className={classes.welcomeCard}>
                    {requiresVerification && <ConfirmVerificationPin
                        progressInfo={getProgressInfo(requiresVerification, 'verification')}
                        onVerified={() => trackEvent(AnalyticsEvents.SIGNUP_COMPLETED)}/>}
                    {(!requiresVerification && organizerExists) &&
                        <CreateEvent progressInfo={getProgressInfo(requiresVerification, 'event')}/>}
                    {(!requiresVerification && !organizerExists) && <CreateOrganizer
                        progressInfo={getProgressInfo(requiresVerification, 'organizer')}/>}
                </Card>

                {(!requiresVerification && organizerExists) && (
                    <Center className={classes.skipSection}>
                        <Button
                            component={NavLink}
                            to={'/manage/events'}
                            variant="subtle"
                            size="sm"
                            c="dimmed"
                        >
                            {t`Skip this step`}
                        </Button>
                    </Center>
                )}
            </Container>
        </div>
    )
}

export default Welcome;
