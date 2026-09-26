<?php

namespace HiEvents\Services\Infrastructure\Media;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper over ffprobe.
 *
 * Kept injectable (see ValidBackgroundMediaRule) so validation and the transcoding job can be
 * tested without ffmpeg present, and so both read media metadata the same way.
 */
class MediaProbe
{
    /** Duration in seconds, or null when the file cannot be probed. */
    public function duration(string $path): ?float
    {
        $format = $this->probe($path)['format'] ?? null;
        $duration = $format['duration'] ?? null;

        return is_numeric($duration) ? (float) $duration : null;
    }

    /** @return array{width: int, height: int}|null */
    public function dimensions(string $path): ?array
    {
        foreach ($this->probe($path)['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? null) !== 'video') {
                continue;
            }

            $width = $stream['width'] ?? null;
            $height = $stream['height'] ?? null;

            if (is_numeric($width) && is_numeric($height)) {
                return ['width' => (int) $width, 'height' => (int) $height];
            }
        }

        return null;
    }

    public function rotation(string $path): ?int
    {
        foreach ($this->probe($path)['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? null) !== 'video') {
                continue;
            }

            foreach ($stream['side_data_list'] ?? [] as $sideData) {
                if (isset($sideData['rotation']) && is_numeric($sideData['rotation'])) {
                    return (int) $sideData['rotation'];
                }
            }

            $tag = $stream['tags']['rotate'] ?? null;

            if (is_numeric($tag)) {
                return (int) $tag;
            }
        }

        return null;
    }

    public function available(): bool
    {
        try {
            return (new Process(['ffprobe', '-version']))->run() === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function probe(string $path): array
    {
        try {
            $process = new Process([
                'ffprobe',
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_format',
                '-show_streams',
                $path,
            ]);
            $process->run();

            if (! $process->isSuccessful()) {
                return [];
            }

            $decoded = json_decode($process->getOutput(), true);

            return is_array($decoded) ? $decoded : [];
        } catch (ProcessFailedException|\Throwable) {
            return [];
        }
    }
}
