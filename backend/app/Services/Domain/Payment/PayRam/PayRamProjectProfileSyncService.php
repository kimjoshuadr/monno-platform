<?php

namespace HiEvents\Services\Domain\Payment\PayRam;

use HiEvents\DomainObjects\Generated\OrganizerPayramAccountDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the organizer's PayRam project profile in step with Monno.
 *
 * Monno is the single source of truth for the organizer's name, website and
 * support address; PayRam only needs enough to brand its checkout and address
 * its emails. The gateway project *name* is always the qualified one
 * ("GN Club (29)"), never the bare organizer name, so two organizers with the
 * same name can never collide.
 *
 * Best-effort: a sync failure must never fail the settings save that triggered
 * it. The logo is not synced here — PayRam takes it through a separate upload.
 */
class PayRamProjectProfileSyncService
{
    public function __construct(
        private readonly PayRamOperatorClient $operatorClient,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly OrganizerPayRamAccountsRepositoryInterface $accountsRepository,
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
    }
}
