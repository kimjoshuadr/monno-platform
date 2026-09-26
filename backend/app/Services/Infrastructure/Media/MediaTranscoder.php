<?php

namespace HiEvents\Services\Infrastructure\Media;

use RuntimeException;
use Symfony\Component\Process\Process;

class MediaTranscoder
{
    public const MAX_WIDTH = 1920;

    public const MAX_HEIGHT = 1080;

    public const POSTER_SECOND = 1.0;

    public function __construct(
        private readonly float $timeout = 300.0,
    ) {}

    public function available(): bool
    {
        try {
            return (new Process(['ffmpeg', '-version']))->run() === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function transcode(string $inputPath, string $outputPath): void
    {
        $scale = sprintf(
            "scale=w='min(%d,iw)':h='min(%d,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
            self::MAX_WIDTH,
            self::MAX_HEIGHT,
        );

        $this->run([
            'ffmpeg',
            '-y',
            '-loglevel', 'error',
            '-i', $inputPath,
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            '-an',
            '-vf', $scale,
            $outputPath,
        ], $outputPath, 'transcode');
    }

    public function extractPoster(string $inputPath, string $outputPath, float $atSecond = self::POSTER_SECOND): void
    {
        $this->run([
            'ffmpeg',
            '-y',
            '-loglevel', 'error',
            '-ss', (string) $atSecond,
            '-i', $inputPath,
            '-frames:v', '1',
            '-q:v', '3',
            $outputPath,
        ], $outputPath, 'poster');
    }

    private function run(array $command, string $expectedOutput, string $context): void
    {
        $process = new Process($command);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($expectedOutput) || filesize($expectedOutput) === 0) {
            throw new RuntimeException(sprintf(
                'ffmpeg %s failed: %s',
                $context,
                trim($process->getErrorOutput()) ?: 'no output produced',
            ));
        }
    }
}
