import {t} from "@lingui/macro";

interface UploadError {
    response?: {
        status?: number;
        data?: {
            errors?: Record<string, string[]>;
            message?: string;
        };
    };
}

export const IMAGE_MAX_UPLOAD_SIZE = 5 * 1024 * 1024;

export const validateImageFile = (file: File): string | null => {
    if (file.size > IMAGE_MAX_UPLOAD_SIZE) {
        return t`File is too large. Maximum size is 5MB.`;
    }
    if (!file.type.startsWith("image/")) {
        return t`Invalid file type. Please upload an image.`;
    }
    return null;
};

export const extractImageUploadErrors = (error: any): string[] => {
    if (error?.response?.data?.errors?.image) {
        return error.response.data.errors.image;
    }
    if (error?.response?.status === 413) {
        return [t`File is too large. Maximum size is 5MB.`];
    }
    if (typeof error?.response?.data?.message === "string" && error.response.data.message) {
        return [error.response.data.message];
    }
    if (!error?.response) {
        return [t`The upload didn't reach the server. Check your connection, and if the file is large, try a smaller image (max 5MB).`];
    }
    return [t`Failed to upload image. Please try again.`];
};

export const BACKGROUND_IMAGE_MAX_UPLOAD_SIZE = 10 * 1024 * 1024;

export const BACKGROUND_IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

export const validateBackgroundImageFile = (file: File): string | null => {
    if (file.size > BACKGROUND_IMAGE_MAX_UPLOAD_SIZE) {
        return t`File is too large. Maximum size is 10MB.`;
    }
    if (!BACKGROUND_IMAGE_MIME_TYPES.includes(file.type)) {
        return t`Invalid file type. Please upload a PNG, JPG, WEBP or GIF image.`;
    }
    return null;
};

export const extractBackgroundImageErrors = (error: unknown): string[] => {
    const uploadError = error as UploadError;
    if (uploadError?.response?.data?.errors?.image) {
        return uploadError.response.data.errors.image;
    }
    if (uploadError?.response?.status === 413) {
        return [t`File is too large. Maximum size is 10MB.`];
    }
    if (typeof uploadError?.response?.data?.message === "string" && uploadError.response.data.message) {
        return [uploadError.response.data.message];
    }
    if (!uploadError?.response) {
        return [t`The upload didn't reach the server. Check your connection, and if the file is large, try a smaller image (max 10MB).`];
    }
    return [t`Failed to upload image. Please try again.`];
};

export const BACKGROUND_VIDEO_MAX_UPLOAD_SIZE = 25 * 1024 * 1024;

export const BACKGROUND_VIDEO_MIME_TYPES = ['video/mp4', 'video/webm'];

export const validateBackgroundVideoFile = (file: File): string | null => {
    if (file.size > BACKGROUND_VIDEO_MAX_UPLOAD_SIZE) {
        return t`File is too large. Maximum size is 25MB.`;
    }
    if (!BACKGROUND_VIDEO_MIME_TYPES.includes(file.type)) {
        return t`Invalid file type. Please upload an MP4 or WEBM video.`;
    }
    return null;
};

export const extractBackgroundVideoErrors = (error: unknown): string[] => {
    const uploadError = error as UploadError;
    if (uploadError?.response?.data?.errors?.video) {
        return uploadError.response.data.errors.video;
    }
    if (uploadError?.response?.status === 413) {
        return [t`File is too large. Maximum size is 25MB.`];
    }
    if (typeof uploadError?.response?.data?.message === "string" && uploadError.response.data.message) {
        return [uploadError.response.data.message];
    }
    if (!uploadError?.response) {
        return [t`The upload didn't reach the server. Check your connection, and if the file is large, try a smaller video (max 25MB).`];
    }
    return [t`Failed to upload video. Please try again.`];
};
