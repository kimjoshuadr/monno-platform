<?php

namespace HiEvents\Jobs\Image;

use HiEvents\DomainObjects\Enums\ImageProcessingState;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Infrastructure\Image\ImageStorageService;
use HiEvents\Services\Infrastructure\Media\MediaProbe;
use HiEvents\Services\Infrastructure\Media\MediaTranscoder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class ProcessBackgroundVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private ?string $workDir = null;

    private ?string $posterDisk = null;

    private ?string $posterPath = null;

    private ?int $posterRowId = null;

    public function __construct(
        public readonly int $imageId,
    ) {}

    public function handle(
        MediaProbe $probe,
        MediaTranscoder $transcoder,
        ImageStorageService $imageStorageService,
        ImageRepositoryInterface $imageRepository,
        FilesystemManager $filesystemManager,
        LoggerInterface $logger,
    ): void {
        $video = $imageRepository->findFirst($this->imageId);

        if (! $video instanceof ImageDomainObject) {
            return;
        }

        $imageRepository->updateFromArray($video->getId(), [
            'state' => ImageProcessingState::PENDING->name,
            'state_reason' => null,
        ]);

        try {
            $this->process($video, $probe, $transcoder, $imageStorageService, $imageRepository, $filesystemManager, $logger);

            $imageRepository->updateFromArray($video->getId(), [
                'state' => ImageProcessingState::READY->name,
                'state_reason' => null,
            ]);
        } catch (Throwable $e) {
            $this->cleanup($video, $imageStorageService, $imageRepository, $filesystemManager, $logger);

            $imageRepository->updateFromArray($video->getId(), [
                'state' => ImageProcessingState::FAILED->name,
                'state_reason' => Str::limit($e->getMessage(), 500),
            ]);

            $logger->error('Background video processing failed', [
                'image_id' => $video->getId(),
                'message' => $e->getMessage(),
            ]);
        } finally {
            $this->removeWorkDir($logger);
        }
    }

    private function process(
        ImageDomainObject $video,
        MediaProbe $probe,
        MediaTranscoder $transcoder,
        ImageStorageService $imageStorageService,
        ImageRepositoryInterface $imageRepository,
        FilesystemManager $filesystemManager,
        LoggerInterface $logger,
    ): void {
        if (! $transcoder->available()) {
            throw new RuntimeException('ffmpeg is not available on this host.');
        }

        $diskName = $video->getDisk();
        $storedPath = $video->getPath();
        $disk = $filesystemManager->disk($diskName);

        if ($storedPath === null || ! $disk->exists($storedPath)) {
            throw new RuntimeException('The uploaded video could not be read from storage.');
        }

        $this->workDir = $this->makeWorkDir();
        $sourcePath = $this->workDir.'/source';
        $transcodedPath = $this->workDir.'/transcoded.mp4';
        $posterFilePath = $this->workDir.'/poster.jpg';

        $source = $disk->get($storedPath);

        if ($source === null) {
            throw new RuntimeException('The uploaded video could not be read from storage.');
        }

        file_put_contents($sourcePath, $source);

        $duration = $probe->duration($sourcePath);
        $inputDimensions = $probe->dimensions($sourcePath);
        $rotation = $probe->rotation($sourcePath);

        if ($duration === null || $inputDimensions === null) {
            throw new RuntimeException('The uploaded file is not a readable video.');
        }

        $logger->debug('Probed background video for transcoding', [
            'image_id' => $video->getId(),
            'duration' => $duration,
            'rotation' => $rotation,
            'dimensions' => $inputDimensions,
        ]);

        $transcoder->transcode($sourcePath, $transcodedPath);
        $this->extractPoster($transcoder, $sourcePath, $posterFilePath);

        $outputDimensions = $probe->dimensions($transcodedPath) ?? $inputDimensions;

        $size = $imageStorageService->replace($diskName, $storedPath, $transcodedPath);

        $imageRepository->updateFromArray($video->getId(), [
            'size' => $size,
            'mime_type' => 'video/mp4',
            'width' => $outputDimensions['width'],
            'height' => $outputDimensions['height'],
        ]);

        $this->storePoster($video, $probe, $imageStorageService, $imageRepository, $posterFilePath);
    }

    private function extractPoster(MediaTranscoder $transcoder, string $sourcePath, string $posterFilePath): void
    {
        try {
            $transcoder->extractPoster($sourcePath, $posterFilePath, MediaTranscoder::POSTER_SECOND);
        } catch (Throwable) {
            $transcoder->extractPoster($sourcePath, $posterFilePath, 0.0);
        }
    }

    private function storePoster(
        ImageDomainObject $video,
        MediaProbe $probe,
        ImageStorageService $imageStorageService,
        ImageRepositoryInterface $imageRepository,
        string $posterFilePath,
    ): void {
        $posterType = $this->posterTypeFor($video->getType());

        $storedPoster = $imageStorageService->store(
            new UploadedFile($posterFilePath, 'poster.jpg', 'image/jpeg', null, true),
            $posterType,
        );

        $this->posterDisk = $storedPoster->disk;
        $this->posterPath = $storedPoster->path;

        $posterDimensions = $probe->dimensions($posterFilePath);

        $posterRow = $imageRepository->create([
            'account_id' => $video->getAccountId(),
            'entity_id' => $video->getEntityId(),
            'entity_type' => $video->getEntityType(),
            'type' => $posterType,
            'filename' => $storedPoster->filename,
            'disk' => $storedPoster->disk,
            'path' => $storedPoster->path,
            'size' => $storedPoster->size,
            'mime_type' => 'image/jpeg',
            'width' => $posterDimensions['width'] ?? null,
            'height' => $posterDimensions['height'] ?? null,
            'state' => ImageProcessingState::READY->name,
        ]);

        $this->posterRowId = $posterRow->getId();
    }

    private function posterTypeFor(?string $videoType): string
    {
        return match ($videoType) {
            ImageType::EVENT_BACKGROUND->name => ImageType::EVENT_BACKGROUND_POSTER->name,
            ImageType::ORGANIZER_BACKGROUND->name => ImageType::ORGANIZER_BACKGROUND_POSTER->name,
            default => throw new RuntimeException('Unsupported background video type: '.$videoType),
        };
    }

    private function cleanup(
        ImageDomainObject $video,
        ImageStorageService $imageStorageService,
        ImageRepositoryInterface $imageRepository,
        FilesystemManager $filesystemManager,
        LoggerInterface $logger,
    ): void {
        try {
            if ($this->posterRowId !== null) {
                $imageRepository->deleteById($this->posterRowId);
            }
        } catch (Throwable $e) {
            $logger->warning('Could not remove the partial poster row.', ['message' => $e->getMessage()]);
        }

        try {
            if ($this->posterDisk !== null && $this->posterPath !== null) {
                $imageStorageService->delete($this->posterDisk, $this->posterPath);
            }
        } catch (Throwable $e) {
            $logger->warning('Could not remove the partial poster object.', ['message' => $e->getMessage()]);
        }

        try {
            if ($video->getDisk() !== null && $video->getPath() !== null) {
                $imageStorageService->delete($video->getDisk(), $video->getPath());
            }
        } catch (Throwable $e) {
            $logger->warning('Could not remove the original video object.', ['message' => $e->getMessage()]);
        }

        $this->removeWorkDir($logger);
    }

    private function makeWorkDir(): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'bg_video_'.Str::random(12);

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create a temporary working directory.');
        }

        return $directory;
    }

    private function removeWorkDir(LoggerInterface $logger): void
    {
        if ($this->workDir === null || ! is_dir($this->workDir)) {
            return;
        }

        try {
            foreach (glob($this->workDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($this->workDir);
        } catch (Throwable $e) {
            $logger->warning('Could not remove the temporary working directory.', ['message' => $e->getMessage()]);
        }
    }
}
