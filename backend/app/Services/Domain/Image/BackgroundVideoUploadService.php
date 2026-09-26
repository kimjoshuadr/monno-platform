<?php

namespace HiEvents\Services\Domain\Image;

use HiEvents\DomainObjects\Enums\ImageProcessingState;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Infrastructure\Image\ImageStorageService;
use Illuminate\Http\UploadedFile;

class BackgroundVideoUploadService
{
    public function __construct(
        private readonly ImageStorageService $imageStorageService,
        private readonly ImageRepositoryInterface $imageRepository,
    ) {}

    public function upload(
        UploadedFile $video,
        int $entityId,
        string $entityType,
        string $imageType,
        int $accountId,
    ): ImageDomainObject {
        $storedVideo = $this->imageStorageService->store($video, $imageType);

        return $this->imageRepository->create([
            'account_id' => $accountId,
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'type' => $imageType,
            'filename' => $storedVideo->filename,
            'disk' => $storedVideo->disk,
            'path' => $storedVideo->path,
            'size' => $storedVideo->size,
            'mime_type' => $storedVideo->mime_type,
            'width' => null,
            'height' => null,
            'state' => ImageProcessingState::PENDING->name,
        ]);
    }
}
