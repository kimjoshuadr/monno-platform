<?php

namespace HiEvents\Services\Domain\Payment\PayRam;

use HiEvents\DomainObjects\Generated\OrganizerPayramAccountDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerPayramAccountDomainObject;
use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;

/**
 * Which PayRam project key an organizer's money moves under.
 *
 * Their own merchant account wins; the shared instance key is only a fallback
 * for organizers who have not onboarded (and for our own staging/QA runs) —
 * payments made under it settle to monno's wallet, not theirs.
 */
class PayRamCredentialResolver
{
    public function __construct(
        private readonly OrganizerPayRamAccountsRepositoryInterface $accountsRepository,
    ) {}

    public function forOrganizer(?int $organizerId): ?string
    {
        if ($organizerId === null || $organizerId <= 0) {
            return null;
        }

        $account = $this->accountsRepository->findFirstWhere([
            OrganizerPayramAccountDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($account === null || $account->getStatus() !== PayRamMerchantProvisioningService::STATUS_READY) {
            return null;
        }

        return $account->getApiKey();
    }

    public function accountForOrganizer(?int $organizerId): ?OrganizerPayramAccountDomainObject
    {
        if ($organizerId === null || $organizerId <= 0) {
            return null;
        }

        return $this->accountsRepository->findFirstWhere([
            OrganizerPayramAccountDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);
    }
}
