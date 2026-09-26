<?php

namespace HiEvents\Validators\Rules;

use Closure;
use HiEvents\Services\Infrastructure\Media\MediaProbe;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validation for a page background — an image (including animated GIF) or a short video.
 *
 * Deliberately separate from CreateImageRequest: covers and logos keep their tighter limits
 * (5 MB, no GIF), while a background is allowed to be larger artwork or a short clip.
 */
class ValidBackgroundMediaRule implements ValidationRule
{
    public const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
    public const MAX_VIDEO_BYTES = 25 * 1024 * 1024;
    public const MAX_VIDEO_SECONDS = 15;
    public const MAX_DIMENSION = 4000;
    public const MIN_BACKGROUND_WIDTH = 1280;
    public const MIN_BACKGROUND_HEIGHT = 720;

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const VIDEO_MIMES = ['video/mp4', 'video/webm'];

    public function __construct(
        private readonly ?MediaProbe $probe = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail(__('The :attribute must be an uploaded file.'));

            return;
        }

        $mime = (string) $value->getMimeType();
        $bytes = (int) $value->getSize();

        if (in_array($mime, self::IMAGE_MIMES, true)) {
            $this->validateImage($value, $bytes, $fail);

            return;
        }

        if (in_array($mime, self::VIDEO_MIMES, true)) {
            $this->validateVideo($value, $bytes, $fail);

            return;
        }

        $fail(__('Backgrounds must be a JPEG, PNG, WebP or GIF image, or an MP4 or WebM video.'));
    }

    private function validateImage(UploadedFile $file, int $bytes, Closure $fail): void
    {
        if ($bytes > self::MAX_IMAGE_BYTES) {
            $fail(__('Background images must be 10 MB or smaller.'));

            return;
        }

        $size = @getimagesize($file->getRealPath());

        if ($size === false) {
            $fail(__('That file is not a readable image.'));

            return;
        }

        [$width, $height] = $size;

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            $fail(__('Background images must be at most :max x :max pixels.', ['max' => self::MAX_DIMENSION]));
        }
    }

    private function validateVideo(UploadedFile $file, int $bytes, Closure $fail): void
    {
        if ($bytes > self::MAX_VIDEO_BYTES) {
            $fail(__('Background videos must be 25 MB or smaller.'));

            return;
        }

        $probe = $this->probe ?? app(MediaProbe::class);
        $duration = $probe->duration($file->getRealPath());

        if ($duration === null) {
            $fail(__('That video could not be read. Try an MP4 or WebM file.'));

            return;
        }

        if ($duration > self::MAX_VIDEO_SECONDS) {
            $fail(__('Background videos must be :max seconds or shorter.', ['max' => self::MAX_VIDEO_SECONDS]));
        }
    }
}
