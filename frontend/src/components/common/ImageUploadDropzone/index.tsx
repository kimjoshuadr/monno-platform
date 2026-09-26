import {useEffect, useRef, useState} from "react";
import {Dropzone, FileRejection, IMAGE_MIME_TYPE} from "@mantine/dropzone";
import {useUploadImage} from "../../../mutations/useUploadImage.ts";
import {useDeleteImage} from "../../../mutations/useDeleteImage.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../utilites/confirmationDialog.tsx";
import {ActionIcon, Button, Group, Loader, Text} from "@mantine/core";
import {IconReplace, IconTrash, IconUpload} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {GenericDataResponse, IdParam, Image, ImageType} from "../../../types.ts";
import {
    extractImageUploadErrors,
    IMAGE_MAX_UPLOAD_SIZE,
    validateImageFile,
} from "../../../utilites/imageUploadValidation.ts";
import classes from "./ImageUploadDropzone.module.scss";

interface ImageUploadDropzoneProps {
    disabled?: boolean;
    helpText?: string;
    imageType: ImageType;
    entityId: IdParam;
    onUploadSuccess?: (image: Image) => void;
    onDeleteSuccess?: () => void;
    existingImageData?: {
        url?: string;
        id?: IdParam;
    };
    displayMode?: 'normal' | 'compact';
    accept?: string[];
    maxSize?: number;
    validateFile?: (file: File) => string | null;
    extractErrors?: (error: unknown) => string[];
    upload?: (file: File) => Promise<GenericDataResponse<Image>>;
    previewKind?: 'image' | 'video';
    hintText?: string;
    successMessage?: string;
    dataTestId?: string;
}

export const ImageUploadDropzone = ({
                                        disabled,
                                        helpText,
                                        imageType,
                                        entityId,
                                        existingImageData,
                                        onUploadSuccess,
                                        onDeleteSuccess,
                                        displayMode = 'normal',
                                        accept = IMAGE_MIME_TYPE,
                                        maxSize = IMAGE_MAX_UPLOAD_SIZE,
                                        validateFile = validateImageFile,
                                        extractErrors,
                                        upload,
                                        previewKind = 'image',
                                        hintText,
                                        successMessage,
                                        dataTestId,
                                    }: ImageUploadDropzoneProps) => {
    const [loading, setLoading] = useState(false);
    const [previewImage, setPreviewImage] = useState(existingImageData?.url || null);
    const [imageId, setImageId] = useState(existingImageData?.id || null);
    const [errors, setErrors] = useState<string[]>([]);
    const fileInputRef = useRef<HTMLInputElement | null>(null);

    const uploadImage = useUploadImage();
    const deleteImage = useDeleteImage();

    useEffect(() => {
        if (existingImageData?.url) {
            setPreviewImage(existingImageData.url);
            setImageId(existingImageData.id || null);
        }
    }, [existingImageData]);

    const handleReject = (fileRejections: FileRejection[]) => {
        const errorMessages = fileRejections.flatMap((rejection) =>
            rejection.errors.map((error) => {
                if (error.code === 'file-too-large') {
                    return t`File is too large. Maximum size is 5MB.`;
                }
                if (error.code === 'file-invalid-type') {
                    return t`Invalid file type. Please upload an image.`;
                }
                return error.message;
            })
        );
        setErrors(errorMessages);
    };

    const handleDrop = async (files: File[]) => {
        const [file] = files;
        if (!file) return;

        const validationError = validateFile(file);
        if (validationError) {
            setErrors([validationError]);
            return;
        }

        setErrors([]);
        setLoading(true);

        try {
            const response = upload
                ? await upload(file)
                : await uploadImage.mutateAsync({image: file, imageType, entityId});

            const uploadedUrl = response?.data?.url;
            const uploadedId = response?.data?.id;

            if (uploadedUrl && uploadedId) {
                setPreviewImage(uploadedUrl);
                setImageId(uploadedId);
                showSuccess(successMessage ?? t`Image uploaded successfully`);
                onUploadSuccess?.(response.data);
            }
            setErrors([]);
        } catch (error) {
            console.error(error);
            setErrors(extractErrors ? extractErrors(error) : extractImageUploadErrors(error));
        } finally {
            setLoading(false);
        }
    };

    const handleDelete = () => {
        if (!previewImage || !imageId) return;

        confirmationDialog(t`Are you sure you want to delete this image?`, () => {
            setLoading(true);
            setErrors([]);

            deleteImage.mutate(
                {imageId},
                {
                    onSuccess: () => {
                        setPreviewImage(null);
                        setImageId(null);
                        showSuccess(t`Image deleted successfully`);
                        setErrors([]);
                        onDeleteSuccess?.();
                    },
                    onError: (error) => {
                        console.error(error);
                        showError(t`Something went wrong while deleting the image. Please try again.`);
                    },
                    onSettled: () => setLoading(false),
                }
            );
        });
    };

    const handleReplace = () => {
        fileInputRef.current?.click();
    };

    const renderDropzoneContent = () => {
        if (loading) {
            return (
                <div className={classes.loadingContainer}>
                    <Loader size={displayMode === 'compact' ? 'sm' : 'md'}/>
                    {displayMode !== 'compact' && (
                        <Text size="sm" mt="xs" c="dimmed">
                            {t`Processing upload...`}
                        </Text>
                    )}
                </div>
            );
        }

        if (previewImage) {
            return (
                <div className={classes.previewContainer}>
                    {previewKind === 'video' ? (
                        <video src={previewImage} className={classes.previewImage} controls muted/>
                    ) : (
                        <img src={previewImage} alt={t`Uploaded preview`} className={classes.previewImage}/>
                    )}
                    <Button
                        variant="light"
                        color="blue"
                        size="xs"
                        leftSection={<IconReplace size={14}/>}
                        onClick={handleReplace}
                        className={classes.replaceButton}
                    >
                        {previewKind === 'video' ? t`Replace Video` : t`Replace Image`}
                    </Button>
                </div>
            );
        }

        if (displayMode === 'compact') {
            return (
                <div className={classes.emptyDropzoneCompact}>
                    <Group justify="center" gap="xs">
                        <IconUpload size={20} stroke={1.5}/>
                        <Text size="sm" fw={500}>
                            {t`Click to upload`}
                        </Text>
                    </Group>
                    {helpText && (
                        <Text ta="center" c="dimmed" size="xs" mt={4}>
                            {helpText}
                        </Text>
                    )}
                </div>
            );
        }

        return (
            <div className={classes.emptyDropzone}>
                <Group justify="center">
                    <div className={classes.iconWrapper}>
                        <IconUpload size={36} stroke={1.5}/>
                    </div>
                </Group>
                <Text ta="center" fw={600} size="md" mt="md">
                    {t`Drag & drop or click to upload`}
                </Text>
                {helpText && (
                    <Text ta="center" c="dimmed" size="sm" mt="xs">
                        {helpText}
                    </Text>
                )}
                <Text ta="center" c="dimmed" size="xs" mt="xs">
                    {hintText ?? t`Images only · Max 5MB`}
                </Text>
            </div>
        );
    };

    return (
        <div className={`${classes.outerContainer} ${displayMode === 'compact' ? classes.compact : ''}`}>
            <div className={classes.container}>
                <Dropzone
                    onDrop={handleDrop}
                    onReject={handleReject}
                    accept={accept}
                    maxSize={maxSize}
                    disabled={disabled || loading}
                    className={classes.dropzone}
                    data-testid={dataTestId}
                    classNames={{
                        root: `${classes.dropzoneRoot} ${errors.length > 0 ? classes.dropzoneError : ''}`,
                        inner: classes.dropzoneInner
                    }}
                >
                    {renderDropzoneContent()}
                    <input
                        type="file"
                        accept={accept.join(",")}
                        style={{display: "none"}}
                        ref={fileInputRef}
                        data-testid={dataTestId ? `${dataTestId}-input` : undefined}
                        onChange={(e) => {
                            if (e.target.files?.length) handleDrop(Array.from(e.target.files));
                        }}
                    />
                </Dropzone>
            </div>

            {errors.length > 0 && (
                <div className={classes.errorContainer} data-testid={dataTestId ? `${dataTestId}-errors` : undefined}>
                    {errors.map((error, index) => (
                        <Text key={index} size="xs" c="red">
                            {error}
                        </Text>
                    ))}
                </div>
            )}

            {previewImage && imageId && (
                <Group justify="end" mt="xs">
                    <ActionIcon
                        variant="outline"
                        color="red"
                        title={t`Delete image`}
                        onClick={handleDelete}
                        disabled={loading}
                    >
                        <IconTrash size={14}/>
                    </ActionIcon>
                </Group>
            )}
        </div>
    );
}
