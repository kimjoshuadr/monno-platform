import {useEffect, useRef, useState} from "react";
import {Alert, Group, Loader, Slider, Stack, Text} from "@mantine/core";
import {t} from "@lingui/macro";
import {IconAlertCircle, IconColorPicker, IconPhoto, IconVideo} from "@tabler/icons-react";
import {CustomSelect} from "../CustomSelect";
import {ThemeColorControls} from "../ThemeColorControls";
import {ImageUploadDropzone} from "../ImageUploadDropzone";
import {BackgroundMediaImageType, HomepageThemeSettings, IdParam, Image,} from "../../../types.ts";
import {imageClient} from "../../../api/image.client.ts";
import {
    BACKGROUND_IMAGE_MAX_UPLOAD_SIZE,
    BACKGROUND_IMAGE_MIME_TYPES,
    BACKGROUND_VIDEO_MAX_UPLOAD_SIZE,
    BACKGROUND_VIDEO_MIME_TYPES,
    extractBackgroundImageErrors,
    extractBackgroundVideoErrors,
    validateBackgroundImageFile,
    validateBackgroundVideoFile,
} from "../../../utilites/imageUploadValidation.ts";

type MediaSettings = Pick<HomepageThemeSettings,
    'background_type' | 'background_image_url' | 'background_video_url' | 'background_poster_url'>;

const pickMedia = (values: Partial<HomepageThemeSettings>): MediaSettings => ({
    background_type: values.background_type ?? 'COLOR',
    background_image_url: values.background_image_url ?? null,
    background_video_url: values.background_video_url ?? null,
    background_poster_url: values.background_poster_url ?? null,
});

const POSTER_POLL_DELAY = 1200;
const POSTER_POLL_INTERVAL = 1500;

interface BackgroundControlsProps {
    values: Partial<HomepageThemeSettings>;
    onChange: (values: Partial<HomepageThemeSettings>) => void;
    imageType: BackgroundMediaImageType;
    entityId: IdParam;
    disabled?: boolean;
    refetchImages: () => Promise<Image[] | undefined>;
}

export const BackgroundControls = ({
    values,
    onChange,
    imageType,
    entityId,
    disabled = false,
    refetchImages,
}: BackgroundControlsProps) => {
    const valuesRef = useRef(values);
    valuesRef.current = values;

    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    const refetchRef = useRef(refetchImages);
    refetchRef.current = refetchImages;

    const previousMediaRef = useRef<MediaSettings | null>(null);
    const [processingVideo, setProcessingVideo] = useState<{ id: IdParam; url: string } | null>(null);
    const [processingError, setProcessingError] = useState<string | null>(null);

    const backgroundType = values.background_type ?? 'COLOR';
    const placement = values.background_placement ?? 'PAGE';
    const overlayOpacity = values.background_overlay_opacity ?? 0.35;
    const blur = values.background_blur ?? 0;

    const updateTheme = (partial: Partial<HomepageThemeSettings>) => {
        onChangeRef.current({...valuesRef.current, ...partial});
    };

    useEffect(() => {
        if (!processingVideo) {
            return;
        }

        let cancelled = false;

        const checkState = async () => {
            const rows = await refetchRef.current();
            if (cancelled || !rows) {
                return;
            }

            const row = rows.find((image) => String(image.id) === String(processingVideo.id));
            if (!row) {
                return;
            }

            if (row.state === 'READY') {
                const poster = rows
                    .filter((image) => image.type === `${imageType}_POSTER`)
                    .sort((a, b) => Number(a.id) - Number(b.id))
                    .pop();

                onChangeRef.current({
                    ...valuesRef.current,
                    background_type: 'VIDEO',
                    background_video_url: processingVideo.url,
                    background_poster_url: poster?.url ?? null,
                });
                setProcessingVideo(null);
                setProcessingError(null);
            } else if (row.state === 'FAILED') {
                const previous = previousMediaRef.current;
                onChangeRef.current({
                    ...valuesRef.current,
                    ...(previous ?? {background_type: 'COLOR'}),
                });
                setProcessingError(row.state_reason || t`The video could not be processed. Please try a different clip.`);
                setProcessingVideo(null);
            }
        };

        const firstCheck = setTimeout(checkState, POSTER_POLL_DELAY);
        const interval = setInterval(checkState, POSTER_POLL_INTERVAL);

        return () => {
            cancelled = true;
            clearTimeout(firstCheck);
            clearInterval(interval);
        };
    }, [processingVideo, imageType]);

    const handleImageUploaded = (image: Image) => {
        updateTheme({
            background_type: 'IMAGE',
            background_image_url: image.url,
        });
    };

    const handleVideoUploaded = (image: Image) => {
        previousMediaRef.current = pickMedia(valuesRef.current);
        setProcessingError(null);
        updateTheme({
            background_type: 'VIDEO',
            background_video_url: image.url,
            background_poster_url: null,
        });
        setProcessingVideo({id: image.id, url: image.url});
    };

    const uploadVideo = (file: File) => imageClient.uploadBackgroundVideo(file, imageType, entityId);

    return (
        <Stack gap="md">
            <CustomSelect
                optionList={[
                    {
                        icon: <IconColorPicker/>,
                        label: t`Color`,
                        value: 'COLOR',
                        description: t`Choose a color for your background`,
                    },
                    {
                        icon: <IconPhoto/>,
                        label: t`Image`,
                        value: 'IMAGE',
                        description: t`Upload an image or animated GIF`,
                    },
                    {
                        icon: <IconVideo/>,
                        label: t`Video`,
                        value: 'VIDEO',
                        description: t`Upload a short looping video`,
                    },
                ]}
                label={t`Background Type`}
                name={'homepage_theme_settings.background_type'}
                value={backgroundType}
                onChange={(value) => updateTheme({background_type: (Array.isArray(value) ? value[0] : value) as HomepageThemeSettings['background_type']})}
                disabled={disabled}
                dataTestId="background-type-select"
            />

            {backgroundType !== 'COLOR' && (
                <CustomSelect
                    optionList={[
                        {
                            label: t`Whole page`,
                            value: 'PAGE',
                            description: t`The media fills the whole page behind the content`,
                        },
                        {
                            label: t`Hero band`,
                            value: 'HERO',
                            description: t`The media fills the top band only`,
                        },
                    ]}
                    label={t`Placement`}
                    name={'homepage_theme_settings.background_placement'}
                    value={placement}
                    onChange={(value) => updateTheme({background_placement: (Array.isArray(value) ? value[0] : value) as HomepageThemeSettings['background_placement']})}
                    disabled={disabled}
                    dataTestId="background-placement-select"
                />
            )}

            {backgroundType === 'IMAGE' && (
                <div>
                    <Text fw={500} size="sm" mb="xs">{t`Background Image`}</Text>
                    <ImageUploadDropzone
                        imageType={imageType}
                        entityId={entityId}
                        disabled={disabled}
                        accept={BACKGROUND_IMAGE_MIME_TYPES}
                        maxSize={BACKGROUND_IMAGE_MAX_UPLOAD_SIZE}
                        validateFile={validateBackgroundImageFile}
                        extractErrors={extractBackgroundImageErrors}
                        onUploadSuccess={handleImageUploaded}
                        existingImageData={{url: values.background_image_url ?? undefined}}
                        helpText={t`Displayed behind your page. We recommend 1280x720 or larger.`}
                        hintText={t`PNG, JPG, WEBP or GIF · Max 10MB`}
                        successMessage={t`Image uploaded successfully`}
                        dataTestId="background-image-upload"
                    />
                </div>
            )}

            {backgroundType === 'VIDEO' && (
                <div>
                    <Text fw={500} size="sm" mb="xs">{t`Background Video`}</Text>
                    <ImageUploadDropzone
                        imageType={imageType}
                        entityId={entityId}
                        disabled={disabled}
                        accept={BACKGROUND_VIDEO_MIME_TYPES}
                        maxSize={BACKGROUND_VIDEO_MAX_UPLOAD_SIZE}
                        validateFile={validateBackgroundVideoFile}
                        extractErrors={extractBackgroundVideoErrors}
                        upload={uploadVideo}
                        previewKind="video"
                        onUploadSuccess={handleVideoUploaded}
                        existingImageData={{url: values.background_video_url ?? undefined}}
                        helpText={t`A short looping clip. We recommend 1280x720 or larger.`}
                        hintText={t`MP4 or WEBM · Max 25MB · Up to 15 seconds`}
                        successMessage={t`Video uploaded successfully`}
                        dataTestId="background-video-upload"
                    />

                    {processingVideo && (
                        <Group gap="xs" mt="sm" data-testid="background-video-processing">
                            <Loader size="xs"/>
                            <Text size="sm" c="dimmed">{t`Processing video...`}</Text>
                        </Group>
                    )}

                    {processingError && (
                        <Alert
                            mt="sm"
                            color="red"
                            variant="light"
                            icon={<IconAlertCircle size={16}/>}
                            data-testid="background-video-error"
                        >
                            {processingError}
                        </Alert>
                    )}
                </div>
            )}

            {backgroundType !== 'COLOR' && (
                <Stack gap="lg">
                    <div>
                        <Group justify="space-between" mb={4}>
                            <Text fw={500} size="sm">{t`Overlay`}</Text>
                            <Text size="xs" c="dimmed" data-testid="background-overlay-value">
                                {overlayOpacity.toFixed(2)}
                            </Text>
                        </Group>
                        <Slider
                            min={0}
                            max={1}
                            step={0.05}
                            precision={2}
                            label={(value) => value.toFixed(2)}
                            thumbLabel={t`Overlay opacity`}
                            value={overlayOpacity}
                            onChange={(value) => updateTheme({background_overlay_opacity: value})}
                            disabled={disabled}
                            data-testid="background-overlay-slider"
                        />
                    </div>

                    <div>
                        <Group justify="space-between" mb={4}>
                            <Text fw={500} size="sm">{t`Blur`}</Text>
                            <Text size="xs" c="dimmed" data-testid="background-blur-value">
                                {t`${blur}px`}
                            </Text>
                        </Group>
                        <Slider
                            min={0}
                            max={24}
                            step={1}
                            label={(value) => t`${value}px`}
                            thumbLabel={t`Background blur`}
                            value={blur}
                            onChange={(value) => updateTheme({background_blur: value})}
                            disabled={disabled}
                            data-testid="background-blur-slider"
                        />
                    </div>
                </Stack>
            )}

            <ThemeColorControls
                values={values}
                onChange={onChange}
                disabled={disabled}
            />
        </Stack>
    );
};

export default BackgroundControls;
