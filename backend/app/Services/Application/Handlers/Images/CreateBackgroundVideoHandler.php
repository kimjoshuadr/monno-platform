<?php

namespace HiEvents\Services\Application\Handlers\Images;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Jobs\Image\ProcessBackgroundVideoJob;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Images\DTO\CreateBackgroundVideoDTO;
use HiEvents\Services\Domain\Image\BackgroundVideoUploadService;
use HiEvents\Services\Infrastructure\Image\Exception\CouldNotUploadImageException;

class CreateBackgroundVideoHandler
{
    public function __construct(
        private readonly BackgroundVideoUploadService $backgroundVideoUploadService,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    public function handle(CreateBackgroundVideoDTO $videoData): ImageDomainObject
    {
        $entityType = $videoData->imageType->getEntityType();

        $this->validateEntityBelongsToAccount($videoData->accountId, $videoData->entityId, $entityType);

        $video = $this->backgroundVideoUploadService->upload(
            video: $videoData->video,
            entityId: $videoData->entityId,
            entityType: $entityType,
            imageType: $videoData->imageType->name,
            accountId: $videoData->accountId,
        );

        ProcessBackgroundVideoJob::dispatch($video->getId());

        return $video;
    }

    private function validateEntityBelongsToAccount(int $accountId, int $entityId, string $entityType): void
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
}
