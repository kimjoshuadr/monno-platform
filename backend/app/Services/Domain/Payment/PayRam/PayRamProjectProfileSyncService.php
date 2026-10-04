<?php

namespace HiEvents\Services\Domain\Payment\PayRam;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\Generated\OrganizerPayramAccountDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the organizer's PayRam project profile in step with Monno.
 *
 * Monno is the single source of truth for the organizer's name, website, support
 * address and brand image; PayRam only needs enough to brand its checkout and
 * address its emails. The gateway project *name* is always the qualified one
 * ("GN Club (29)"), never the bare organizer name, so two organizers with the
 * same name can never collide.
 *
 * Best-effort: a sync failure must never fail the settings save that triggered
 * it.
 */
class PayRamProjectProfileSyncService
{
    private const LOGO_SYNCED_CACHE_PREFIX = 'payram:logo_synced:';

    public function __construct(
        private readonly PayRamOperatorClient $operatorClient,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly OrganizerPayRamAccountsRepositoryInterface $accountsRepository,
        private readonly ImageRepositoryInterface $imageRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function syncForOrganizer(int $organizerId): void
    {
        $account = $this->accountsRepository->findFirstWhere([
            OrganizerPayramAccountDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        $projectId = $account?->getExternalPlatformId();

        if ($projectId === null) {
            return; // No crypto account yet: nothing to sync to.
        }

        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findById($organizerId);

        if ($organizer === null) {
            return;
        }

        try {
            $this->operatorClient->updateProjectProfile(
                projectId: $projectId,
                name: PayRamMerchantProvisioningService::gatewayProjectName($organizer->getName(), $organizerId),
                website: $organizer->getWebsite() ?: null,
                supportEmail: $organizer->getEmail() ?: null,
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Could not sync the organizer profile to PayRam', [
                'organizer_id' => $organizerId,
                'project_id' => $projectId,
                'error' => $exception->getMessage(),
            ]);
        }

        $this->syncLogo($projectId, $organizerId);
    }

    /**
     * PayRam takes the brand image as a multipart upload, not a URL. The image
     * rarely changes, so we only push it when the stored path differs from the
     * last one we synced.
     */
    private function syncLogo(int $projectId, int $organizerId): void
    {
        $image = $this->imageRepository->findFirstWhere([
            'entity_id' => $organizerId,
            'entity_type' => OrganizerDomainObject::class,
            'type' => ImageType::ORGANIZER_LOGO->name,
        ]);

        $path = $image?->getPath();
        $disk = $image?->getDisk();

        if ($path === null || $disk === null) {
            return;
        }

        $cacheKey = self::LOGO_SYNCED_CACHE_PREFIX.$organizerId;
        if (Cache::get($cacheKey) === $path) {
            return; // already synced this image
        }

        try {
            $contents = Storage::disk($disk)->get($path);
        } catch (Throwable $exception) {
            $this->logger->warning('Could not read the organizer logo for PayRam', [
                'organizer_id' => $organizerId,
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if (! is_string($contents) || $contents === '') {
            return;
        }

        try {
            $this->operatorClient->uploadProjectLogo(
                projectId: $projectId,
                contents: $contents,
                filename: (string) ($image->getFilename() ?: 'logo.png'),
                mimeType: (string) ($image->getMimeType() ?: 'application/octet-stream'),
            );

            Cache::put($cacheKey, $path, now()->addDays(30));
        } catch (Throwable $exception) {
            $this->logger->warning('Could not sync the organizer logo to PayRam', [
                'organizer_id' => $organizerId,
                'project_id' => $projectId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
