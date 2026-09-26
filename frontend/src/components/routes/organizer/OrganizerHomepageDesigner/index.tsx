import {useEffect, useRef, useState} from "react";
import classes from './OrganizerHomepageDesigner.module.scss';
import {useParams} from "react-router";
import {useGetOrganizerSettings} from "../../../../queries/useGetOrganizerSettings.ts";
import {useUpdateOrganizerSettings} from "../../../../mutations/useUpdateOrganizerSettings.ts";
import {useFormErrorResponseHandler} from "../../../../hooks/useFormErrorResponseHandler.tsx";
import {HomepageThemeSettings, IdParam, OrganizerSettings} from "../../../../types.ts";
import {showSuccess} from "../../../../utilites/notifications.tsx";
import {t} from "@lingui/macro";
import {useForm} from "@mantine/form";
import {Accordion, Button, Group, Stack, Text} from "@mantine/core";
import {IconHelp, IconPalette, IconPhoto, IconTypography} from "@tabler/icons-react";
import {Tooltip} from "../../../common/Tooltip";
import {LoadingMask} from "../../../common/LoadingMask";
import {GET_ORGANIZER_QUERY_KEY, useGetOrganizer} from "../../../../queries/useGetOrganizer.ts";
import {ImageUploadDropzone} from "../../../common/ImageUploadDropzone";
import {organizerPreviewPath} from "../../../../utilites/urlHelper.ts";
import {queryClient} from "../../../../utilites/queryClient.ts";
import {GET_ORGANIZER_PUBLIC_QUERY_KEY} from "../../../../queries/useGetOrganizerPublic.ts";
import {BackgroundControls} from "../../../common/BackgroundControls";
import {ThemeFontControl} from "../../../common/ThemeFontControl";
import {computeThemeVariables, validateThemeSettings} from "../../../../utilites/themeUtils.ts";
import {DEFAULT_HOMEPAGE_FONT} from "../../../../constants/homepageFonts.ts";

interface FormValues {
    homepage_theme_settings: Partial<HomepageThemeSettings>;
}

const OrganizerHomepageDesigner = () => {
    const {organizerId} = useParams();
    const organizerSettingsQuery = useGetOrganizerSettings(organizerId);
    const organizerQuery = useGetOrganizer(organizerId);
    const updateMutation = useUpdateOrganizerSettings();

    const organizerData = organizerQuery.data;

    const iframeRef = useRef<HTMLIFrameElement>(null);
    const lastSentSettings = useRef<string | null>(null);

    const [iframeSrc, setIframeSrc] = useState<string | null>(null);
    const [iframeLoaded, setIframeLoaded] = useState(false);
    const [accordionValue, setAccordionValue] = useState<string[]>(['images', 'theme', 'typography']);
    const [lastCoverId, setLastCoverId] = useState<IdParam | null>(null);
    const [lastLogoId, setLastLogoId] = useState<IdParam | null>(null);

    const existingLogo = organizerData?.images?.find((image) => image.type === 'ORGANIZER_LOGO');
    const existingCover = organizerData?.images?.find((image) => image.type === 'ORGANIZER_COVER');

    const form = useForm<FormValues>({
        initialValues: {
            homepage_theme_settings: {
                accent: '#0B0B0C',
                background: '#F5F6F8',
                mode: 'light',
                background_type: 'COLOR',
                font_family: DEFAULT_HOMEPAGE_FONT,
            },
        }
    });

    const formErrorHandle = useFormErrorResponseHandler();

    useEffect(() => {
        if (organizerSettingsQuery?.isFetched && organizerSettingsQuery?.data) {
            const settings = organizerSettingsQuery.data;
            const themeSettings = validateThemeSettings(settings.homepage_theme_settings);

            form.setValues({
                homepage_theme_settings: themeSettings,
            });
        }
    }, [organizerSettingsQuery.isFetched, organizerSettingsQuery.data]);

    useEffect(() => {
        if (organizerSettingsQuery.isFetched && organizerQuery.isFetched && !iframeSrc) {
            setIframeSrc(organizerPreviewPath(organizerId));
        }
    }, [organizerSettingsQuery.isFetched, organizerQuery.isFetched, organizerId]);

    const handleSubmit = (values: FormValues) => {
        const validatedTheme = validateThemeSettings(values.homepage_theme_settings);

        const organizerSettings: Partial<OrganizerSettings> = {
            homepage_theme_settings: validatedTheme,
        };

        updateMutation.mutate(
            {
                organizerSettings,
                organizerId: organizerId
            },
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

    const sendSettingsToIframeRef = useRef<() => void>(() => undefined);

    const sendSettingsToIframe = () => {
        // Send whenever there is a frame to send to. Gating this on `iframeLoaded` (set by the
        // iframe's onLoad) meant nothing was posted for the organizer preview at all — its
        // loader awaits two queries, so the flag was not set while edits were being made — and
        // section edits never reached the preview. The PREVIEW_READY handshake covers the case
        // where the frame exists before the preview's own listener mounts.
        if (iframeRef.current?.contentWindow) {
            const themeSettings = validateThemeSettings(form.values.homepage_theme_settings);
            const cssVars = computeThemeVariables(themeSettings);

            const settingsToSend = {
                homepage_theme_settings: themeSettings,
                logoUrl: existingLogo?.url,
                coverUrl: existingCover?.url,
                // Include legacy fields for backward compatibility with preview
                homepage_background_color: themeSettings.background,
                homepage_content_background_color: cssVars['--theme-surface'],
                homepage_primary_color: themeSettings.accent,
                homepage_primary_text_color: cssVars['--theme-text-primary'],
                homepage_secondary_color: cssVars['--theme-text-secondary'],
                homepage_secondary_text_color: cssVars['--theme-text-tertiary'],
                homepage_background_type: themeSettings.background_type,
            };

            const settingsJson = JSON.stringify(settingsToSend);
            if (settingsJson !== lastSentSettings.current) {
                iframeRef.current.contentWindow.postMessage(
                    {type: "UPDATE_ORGANIZER_SETTINGS", settings: settingsToSend},
                    "*"
                );
                lastSentSettings.current = settingsJson;
            }
        }
    };

    sendSettingsToIframeRef.current = sendSettingsToIframe;

    useEffect(() => {
        // The preview announces itself once it is listening; resend even if the payload is
        // unchanged, because the first send may have raced its mount.
        const handleReady = (event: MessageEvent) => {
            if (event.data?.type === "PREVIEW_READY") {
                lastSentSettings.current = '';
                sendSettingsToIframeRef.current();
            }
        };

        window.addEventListener("message", handleReady);
        return () => window.removeEventListener("message", handleReady);
    }, [iframeLoaded]);

    useEffect(() => {
        sendSettingsToIframe();
    }, [iframeLoaded, form.values, existingLogo?.url, existingCover?.url]);

    useEffect(() => {
        if (((existingCover?.id !== lastCoverId) || existingLogo?.id !== lastLogoId) && iframeSrc) {
            setLastCoverId(existingCover?.id);
            setLastLogoId(existingLogo?.id);
            setIframeSrc(organizerPreviewPath(organizerId) + `?cover_image_id=${existingCover?.id}&logo_image_id=${existingLogo?.id}`);
            setIframeLoaded(false);
        }
    }, [existingCover?.id, existingLogo?.id]);

    const handleImageChange = () => {
        queryClient.invalidateQueries({
            queryKey: [GET_ORGANIZER_PUBLIC_QUERY_KEY, organizerId],
        });
        queryClient.invalidateQueries({
            queryKey: [GET_ORGANIZER_QUERY_KEY, organizerId],
        });
    };

    const handleThemeChange = (themeSettings: Partial<HomepageThemeSettings>) => {
        form.setFieldValue('homepage_theme_settings', themeSettings);
    };

    return (
        <div className={classes.container}>
            <div className={classes.sidebar}>
                <div className={classes.sticky}>
                    <div className={classes.header}>
                        <h2>{t`Homepage Design`}</h2>
                        <Text c="dimmed" size="sm">{t`Customize your organizer page appearance`}</Text>
                    </div>

                    <Accordion
                        multiple
                        value={accordionValue}
                        onChange={setAccordionValue}
                        variant="contained"
                        className={classes.accordion}
                    >
                        <Accordion.Item value="images" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconPhoto size={20}/>}>
                                <Text fw={500}>{t`Images`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <Stack gap="lg">
                                    <div>
                                        <Group justify={'space-between'} mb="xs">
                                            <Text fw={500} size="sm">{t`Cover Image`}</Text>
                                            <Tooltip
                                                label={t`We recommend dimensions of 1950px by 650px, a ratio of 3:1, and a maximum file size of 5MB`}>
                                                <IconHelp size={16} style={{color: 'var(--mantine-color-gray-6)'}}/>
                                            </Tooltip>
                                        </Group>
                                        <ImageUploadDropzone
                                            imageType="ORGANIZER_COVER"
                                            entityId={organizerId}
                                            onUploadSuccess={handleImageChange}
                                            onDeleteSuccess={handleImageChange}
                                            existingImageData={{
                                                url: existingCover?.url,
                                                id: existingCover?.id,
                                            }}
                                            helpText={t`Cover image will be displayed at the top of your organizer page`}
                                            displayMode="compact"
                                        />
                                    </div>

                                    <div>
                                        <Group justify={'space-between'} mb="xs">
                                            <Text fw={500} size="sm">{t`Logo`}</Text>
                                            <Tooltip label={t`We recommend dimensions of 400px by 400px, and a maximum file size of 5MB`}>
                                                <IconHelp size={16} style={{color: 'var(--mantine-color-gray-6)'}}/>
                                            </Tooltip>
                                        </Group>
                                        <ImageUploadDropzone
                                            imageType="ORGANIZER_LOGO"
                                            entityId={organizerId}
                                            onUploadSuccess={handleImageChange}
                                            onDeleteSuccess={handleImageChange}
                                            existingImageData={{
                                                url: existingLogo?.url,
                                                id: existingLogo?.id,
                                            }}
                                            helpText={t`Logo will be displayed in the header`}
                                            displayMode="compact"
                                        />
                                    </div>
                                </Stack>
                            </Accordion.Panel>
                        </Accordion.Item>

                        <Accordion.Item value="theme" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconPalette size={20}/>}>
                                <Text fw={500}>{t`Theme & Colors`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <form onSubmit={form.onSubmit(handleSubmit)}>
                                    <fieldset disabled={organizerSettingsQuery.isLoading || updateMutation.isPending}
                                              className={classes.fieldset}>
                                        <Stack gap="md">
                                            <BackgroundControls
                                                values={form.values.homepage_theme_settings}
                                                onChange={handleThemeChange}
                                                imageType="ORGANIZER_BACKGROUND"
                                                entityId={organizerId}
                                                disabled={organizerSettingsQuery.isLoading || updateMutation.isPending}
                                                refetchImages={async () => (await organizerQuery.refetch()).data?.images}
                                            />
                                        </Stack>
                                    </fieldset>
                                </form>
                            </Accordion.Panel>
                        </Accordion.Item>

                        <Accordion.Item value="typography" className={classes.accordionItem}>
                            <Accordion.Control icon={<IconTypography size={20}/>}>
                                <Text fw={500}>{t`Typography`}</Text>
                            </Accordion.Control>
                            <Accordion.Panel>
                                <fieldset disabled={organizerSettingsQuery.isLoading || updateMutation.isPending}
                                          className={classes.fieldset}>
                                    <ThemeFontControl
                                        value={form.values.homepage_theme_settings.font_family}
                                        onChange={(fontFamily) => form.setFieldValue('homepage_theme_settings', {
                                            ...form.values.homepage_theme_settings,
                                            font_family: fontFamily,
                                        })}
                                        disabled={organizerSettingsQuery.isLoading || updateMutation.isPending}
                                    />
                                </fieldset>
                            </Accordion.Panel>
                        </Accordion.Item>
                    </Accordion>

                    <Button
                        loading={updateMutation.isPending}
                        type={'submit'}
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
                            title="Organizer Homepage Preview"
                            onLoad={() => {
                                // A freshly loaded document starts with no settings; clear the
                                // send-once cache so the resend below actually posts. Without
                                // this the organizer preview never received anything, because
                                // its loader keeps the frame blank while the first sends happen.
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

export default OrganizerHomepageDesigner;
