<?php

namespace HiEvents\Services\Application\Handlers\Images\DTO;

use HiEvents\DomainObjects\Enums\ImageType;
use Illuminate\Http\UploadedFile;

class CreateBackgroundVideoDTO
{
    public function __construct(
        public readonly int $accountId,
        public readonly UploadedFile $video,
        public readonly ImageType $imageType,
        public readonly int $entityId,
    ) {}
}
