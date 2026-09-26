<?php

namespace HiEvents\Http\Actions\Images;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Image\CreateBackgroundVideoRequest;
use HiEvents\Resources\Image\ImageResource;
use HiEvents\Services\Application\Handlers\Images\CreateBackgroundVideoHandler;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateBackgroundVideoDTO;
use Illuminate\Http\JsonResponse;

class CreateBackgroundVideoAction extends BaseAction
{
    public function __construct(
        public readonly CreateBackgroundVideoHandler $createBackgroundVideoHandler,
    ) {}

    public function __invoke(CreateBackgroundVideoRequest $request): JsonResponse
    {
        $video = $this->createBackgroundVideoHandler->handle(new CreateBackgroundVideoDTO(
            accountId: $this->getAuthenticatedAccountId(),
            video: $request->file('video'),
            imageType: ImageType::fromName($request->input('image_type')),
            entityId: (int) $request->input('entity_id'),
        ));

        return $this->resourceResponse(ImageResource::class, $video);
    }
}
