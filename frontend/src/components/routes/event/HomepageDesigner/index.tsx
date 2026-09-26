import {useEffect, useRef, useState} from "react";
import classes from './HomepageDesigner.module.scss';
import {useParams} from "react-router";
import {useGetEventSettings} from "../../../../queries/useGetEventSettings.ts";
import {useUpdateEventSettings} from "../../../../mutations/useUpdateEventSettings.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {EventSettings, HomepageBlock, HomepageThemeSettings, IdParam} from "../../../../types.ts";
import {showSuccess} from "../../../../utilites/notifications.tsx";
import {t} from "@lingui/macro";
import {useForm} from "@mantine/form";
import {Button, Group, TextInput, Accordion, Stack, Text} from "@mantine/core";
import {IconHelp, IconLayoutList, IconPhoto, IconPalette, IconTypography} from "@tabler/icons-react";
import {Tooltip} from "../../../common/Tooltip";
import {GET_EVENT_IMAGES_QUERY_KEY, useGetEventImages} from "../../../../queries/useGetEventImages.ts";
import {eventPreviewPath} from "../../../../utilites/urlHelper.ts";
import {LoadingMask} from "../../../common/LoadingMask";
import {ImageUploadDropzone} from "../../../common/ImageUploadDropzone";
import {queryClient} from "../../../../utilites/queryClient.ts";
import {GET_EVENT_PUBLIC_QUERY_KEY} from "../../../../queries/useGetEventPublic.ts";
import {BackgroundControls} from "../../../common/BackgroundControls";
import {ThemeFontControl} from "../../../common/ThemeFontControl";
import {validateThemeSettings} from "../../../../utilites/themeUtils.ts";
import {DEFAULT_HOMEPAGE_FONT} from "../../../../constants/homepageFonts.ts";
import {BlockBuilder} from "../../../common/BlockBuilder";

interface FormValues {
    homepage_theme_settings: Partial<HomepageThemeSettings>;
    continue_button_text: string;
    homepage_blocks: HomepageBlock[];
}

const HomepageDesigner = () => {
    const {eventId} = useParams();
    const eventSettingsQuery = useGetEventSettings(eventId);
    const eventImagesQuery = useGetEventImages(eventId);
    const updateMutation = useUpdateEventSettings();

    const iframeRef = useRef<HTMLIFrameElement>(null);
    const lastSentSettings = useRef<string | null>(null);

    const [iframeSrc, setIframeSrc] = useState<string | null>(null);
    const [iframeLoaded, setIframeLoaded] = useState(false);
    const [lastSquareId, setLastSquareId] = useState<IdParam | null>(null);
    const [accordionValue, setAccordionValue] = useState<string[]>(['sections', 'images', 'colors', 'typography', 'button']);

    const existingSquare = eventImagesQuery.data?.find((image) => image.type === 'EVENT_IMAGE');

    const form = useForm<FormValues>({
        initialValues: {
            homepage_theme_settings: {
                accent: '#0B0B0C',
                background: '#F5F6F8',
                mode: 'light',
                background_type: 'COLOR',
                font_family: DEFAULT_HOMEPAGE_FONT,
            },
            continue_button_text: '',
            homepage_blocks: [],
        }
    });

    const formErrorHandle = useFormErrorResponseHandler();

    useEffect(() => {
        if (eventSettingsQuery?.isFetched && eventSettingsQuery?.data) {
            const settings = eventSettingsQuery.data;
            const themeSettings = validateThemeSettings(settings.homepage_theme_settings);

            form.setValues({
                homepage_theme_settings: themeSettings,
                continue_button_text: settings.continue_button_text,
                homepage_blocks: settings.homepage_blocks || [],
            });
        }
    }, [eventSettingsQuery.isFetched]);

    useEffect(() => {
        if (eventSettingsQuery.isFetched && eventImagesQuery.isFetched && !iframeSrc) {
            setIframeSrc(eventPreviewPath(eventId));
        }
    }, [eventSettingsQuery.isFetched, eventImagesQuery.isFetched]);

    useEffect(() => {
        if ((existingSquare?.id !== lastSquareId) && iframeSrc) {
            setLastSquareId(existingSquare?.id);
            setIframeSrc(eventPreviewPath(eventId) + `?event_image_id=${existingSquare?.id}`);
            setIframeLoaded(false);
        }
    }, [existingSquare?.id]);

    const handleSubmit = (values: FormValues) => {
        const validatedTheme = validateThemeSettings(values.homepage_theme_settings);

        const eventSettings: Partial<EventSettings> = {
            homepage_theme_settings: validatedTheme,
            continue_button_text: values.continue_button_text,
            homepage_blocks: values.homepage_blocks,
            // Also update legacy fields for backward compatibility during transition
            homepage_primary_color: validatedTheme.accent,
            homepage_body_background_color: validatedTheme.background,
            homepage_background_type: validatedTheme.background_type,
        };

        updateMutation.mutate(
            {eventSettings, eventId: eventId},
            {
                onSuccess: () => {
                    showSuccess(t`Successfully Updated Homepage Design`);
                },
                onError: (error) => {
                    formErrorHandle(form, error);
                },
            }
        );
    };

    const handleImageChange = () => {
        queryClient.invalidateQueries({
            queryKey: [GET_EVENT_IMAGES_QUERY_KEY, eventId]
        });
        queryClient.invalidateQueries({
            queryKey: [GET_EVENT_PUBLIC_QUERY_KEY, eventId]
        });
    };

    const sendSettingsToIframe = () => {
        if (iframeRef.current?.contentWindow && iframeLoaded) {
            const themeSettings = validateThemeSettings(form.values.homepage_theme_settings);

            const settingsToSend = {
                homepage_theme_settings: themeSettings,
                continue_button_text: form.values.continue_button_text,
                // Sections are page content: send them so the preview reflects edits live.
                homepage_blocks: form.values.homepage_blocks,
            };

            const settingsJson = JSON.stringify(settingsToSend);
            if (settingsJson !== lastSentSettings.current) {
                iframeRef.current.contentWindow.postMessage(
                    {type: "UPDATE_SETTINGS", settings: settingsToSend},
                    "*"
                );
                lastSentSettings.current = settingsJson;
            }
        }
    };

    useEffect(() => {
        sendSettingsToIframe();
    }, [iframeLoaded, form.values]);

    const handleThemeChange = (themeSettings: Partial<HomepageThemeSettings>) => {
        form.setFieldValue('homepage_theme_settings', themeSettings);
    };

    return (
        <div className={classes.container}>
            <div className={classes.sidebar}>
                <div className={classes.sticky}>
                    <div className={classes.header}>
                        <h2>{t`Homepage Design`}</h2>
                        <Text c="dimmed" size="sm">{t`Customize the layout, colors, and branding of your event homepage.`}</Text>
                    </div>

                    <Accordion
                        multiple
                        value={accordionValue}
                        onChange={setAccordionValue}
                        variant="contained"
                        className={classes.accordion}
                    >
                        <Accordion.Item value="images" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconPhoto size={20} />}>
                                <Text fw={500}>{t`Images`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <Stack gap="lg">
                                    <div>
                                        <Group justify={'space-between'} mb="xs">
                                            <Text fw={500} size="sm">{t`Square Event Image`}</Text>
                                            <Tooltip
                                                label={t`We recommend dimensions of 1000px by 1000px, a ratio of 1:1, and a maximum file size of 5MB`}>
                                                <IconHelp size={16} style={{ color: 'var(--mantine-color-gray-6)' }}/>
                                            </Tooltip>
                                        </Group>
                                        <ImageUploadDropzone
                                            imageType="EVENT_IMAGE"
                                            entityId={eventId}
                                            onUploadSuccess={handleImageChange}
                                            onDeleteSuccess={handleImageChange}
                                            existingImageData={{
                                                url: existingSquare?.url,
                                                id: existingSquare?.id,
                                            }}
                                            helpText={t`Square (1:1) image shown on your event page`}
                                            displayMode="compact"
                                            dataTestId="event-square-image-upload"
                                        />
                                    </div>
                                </Stack>
                            </Accordion.Panel>
                        </Accordion.Item>

                        <Accordion.Item value="colors" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconPalette size={20} />}>
                                <Text fw={500}>{t`Theme & Colors`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <form onSubmit={form.onSubmit(handleSubmit)}>
                                    <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending} className={classes.fieldset}>
                                        <Stack gap="md">
                                            <BackgroundControls
                                                values={form.values.homepage_theme_settings}
                                                onChange={handleThemeChange}
                                                imageType="EVENT_BACKGROUND"
                                                entityId={eventId}
                                                disabled={eventSettingsQuery.isLoading || updateMutation.isPending}
                                                refetchImages={async () => (await eventImagesQuery.refetch()).data}
                                            />
                                        </Stack>
                                    </fieldset>
                                </form>
                            </Accordion.Panel>
                        </Accordion.Item>

                        <Accordion.Item value="typography" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconTypography size={20} />}>
                                <Text fw={500}>{t`Typography`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending} className={classes.fieldset}>
                                    <ThemeFontControl
                                        value={form.values.homepage_theme_settings.font_family}
                                        onChange={(fontFamily) => form.setFieldValue('homepage_theme_settings', {
                                            ...form.values.homepage_theme_settings,
                                            font_family: fontFamily,
                                        })}
                                        disabled={eventSettingsQuery.isLoading || updateMutation.isPending}
                                    />
                                </fieldset>
                            </Accordion.Panel>
                        </Accordion.Item>

                        <Accordion.Item value="button" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconTypography size={20} />}>
                                <Text fw={500}>{t`Button Text`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <form onSubmit={form.onSubmit(handleSubmit)}>
                                    <fieldset disabled={eventSettingsQuery.isLoading || updateMutation.isPending} className={classes.fieldset}>
                                        <Stack gap="md">
                                            <TextInput
                                                label={t`Continue Button Text`}
                                                description={t`Customize the text shown on the checkout button in the ticket panel`}
                                                placeholder={t`e.g., Get Tickets, Register Now`}
                                                size="sm"
                                                {...form.getInputProps('continue_button_text')}
                                            />
                                        </Stack>
                                    </fieldset>
                                </form>
                            </Accordion.Panel>
                        </Accordion.Item>
                        <Accordion.Item value="sections" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconLayoutList size={20}/>}>
                                <Text fw={500}>{t`Page sections`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <BlockBuilder
                                    value={form.values.homepage_blocks || []}
                                    onChange={(blocks) => form.setFieldValue('homepage_blocks', blocks)}
                                    // Lock until the server settings hydrate the form — otherwise a
                                    // slow settings response resets homepage_blocks and wipes any
                                    // section the user (or a test) added in the meantime.
                                    disabled={eventSettingsQuery.isLoading || updateMutation.isPending}
                                />
                            </Accordion.Panel>
                        </Accordion.Item>
                    </Accordion>

                    <Button
                        loading={updateMutation.isPending}
                        type="submit"
                        fullWidth
                        mt="md"
                        onClick={() => form.onSubmit(handleSubmit)()}
                    >
                        {t`Save Changes`}
                    </Button>
                </div>
            </div>

            <div className={classes.previewContainer}>
                <h2>{t`Homepage Preview`}</h2>
                <div className={classes.iframeContainer}>
                    {iframeSrc ? (
                        <iframe
                            ref={iframeRef}
                            src={iframeSrc}
                            title="Event Preview"
                            onLoad={() => {
                                // A freshly loaded document starts with no settings; clear the
                                // send-once cache so the resend actually posts.
                                lastSentSettings.current = '';
                                setIframeLoaded(true);
                            }}
                        />
                    ) : (
                        <LoadingMask/>
                    )}
                </div>
            </div>
        </div>
    );
};

export default HomepageDesigner;
