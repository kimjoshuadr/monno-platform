<?php

namespace HiEvents\Services\Application\Handlers\Images;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateImageDTO;
use HiEvents\Services\Domain\Image\ImageUploadService;
use HiEvents\Services\Domain\Payment\PayRam\PayRamProjectProfileSyncService;
use HiEvents\Services\Infrastructure\Image\Exception\CouldNotUploadImageException;
use Throwable;

class CreateImageHandler
{
    private const IMAGES_TYPES_WITH_ONLY_ONE_IMAGE_ALLOWED = [
        ImageType::ORGANIZER_LOGO,
        ImageType::ORGANIZER_COVER,
        ImageType::EVENT_COVER,
        ImageType::EVENT_IMAGE,
        ImageType::TICKET_LOGO,
    ];

    public function __construct(
        private readonly ImageUploadService $imageUploadService,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly PayRamProjectProfileSyncService $payRamProfileSync,
    ) {}

    /**
     * @throws CouldNotUploadImageException
     */
    public function handle(CreateImageDTO $imageData): ImageDomainObject
    {
        if ($imageData->isGeneric()) {
            // For generic images, we associate them with the user
            return $this->imageUploadService->upload(
                image: $imageData->image,
                entityId: $imageData->userId,
                entityType: UserDomainObject::class,
                imageType: ImageType::GENERIC->name,
                accountId: $imageData->accountId,
            );
        }

        if ($imageData->entityId === null) {
            throw new CouldNotUploadImageException('Entity ID is required for non-generic images.');
        }

        $entityType = $imageData->imageType->getEntityType();

        $this->validateEntityBelongsToUser($imageData->accountId, $imageData->entityId, $entityType);

        $this->deleteExistingImages($imageData, $entityType);

        $image = $this->imageUploadService->upload(
            image: $imageData->image,
            entityId: $imageData->entityId,
            entityType: $entityType,
            imageType: $imageData->imageType->name,
            accountId: $imageData->accountId,
        );

        // The organizer's brand image is part of their PayRam project profile,
        // and uploading it is a separate action from saving settings. Without
        // this the new logo sits in Monno and never reaches the gateway.
        // Best-effort: a gateway problem must never fail the upload.
        if ($imageData->imageType === ImageType::ORGANIZER_LOGO && $entityType === OrganizerDomainObject::class) {
            try {
                $this->payRamProfileSync->syncForOrganizer($imageData->entityId);
            } catch (Throwable $exception) {
                logger()->warning('Could not sync the organizer logo to PayRam', [
                    'organizer_id' => $imageData->entityId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $image;
    }

    /**
     * @throws CouldNotUploadImageException
     */
    private function validateEntityBelongsToUser(int $accountId, int $entityId, string $entityType): void
    {
        switch ($entityType) {
            case OrganizerDomainObject::class:
                $organizer = $this->organizerRepository->findById($entityId);
                if ($organizer->getAccountId() !== $accountId) {
                    throw new CouldNotUploadImageException('Organizer does not belong to the user.');
                }
                break;

            case EventDomainObject::class:
                $event = $this->eventRepository->findById($entityId);
                if ($event->getAccountId() !== $accountId) {
                    throw new CouldNotUploadImageException('Event does not belong to the user.');
                }
                break;
        }
    }

    private function deleteExistingImages(CreateImageDTO $imageData, string $entityType): void
    {
        if (in_array($imageData->imageType, self::IMAGES_TYPES_WITH_ONLY_ONE_IMAGE_ALLOWED, true)) {
            $this->imageRepository->deleteWhere([
                'entity_id' => $imageData->entityId,
                'entity_type' => $entityType,
                'type' => $imageData->imageType->name,
            ]);
        }
    }
}
