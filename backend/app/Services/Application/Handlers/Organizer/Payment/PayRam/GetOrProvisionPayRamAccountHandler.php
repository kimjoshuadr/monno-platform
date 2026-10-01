<?php

namespace HiEvents\Services\Application\Handlers\Organizer\Payment\PayRam;

use HiEvents\DomainObjects\OrganizerPayramAccountDomainObject;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Payment\PayRam\PayRamMerchantProvisioningService;

/**
 * The organizer's view of their PayRam merchant account: provision on demand,
 * then report status. Credentials are handed over exactly once, at creation —
 * after that the account row is the record, not a readable secret store.
 */
class GetOrProvisionPayRamAccountHandler
{
    public function __construct(
        private readonly PayRamMerchantProvisioningService $provisioningService,
        private readonly OrganizerRepositoryInterface $organizerRepository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(int $organizerId): array
    {
        $existing = $this->provisioningService->findForOrganizer($organizerId);

        if ($existing !== null
            && $existing->getStatus() !== PayRamMerchantProvisioningService::STATUS_FAILED) {
            return $this->present($existing);
        }

        $organizer = $this->organizerRepository->findById($organizerId);

        $account = $this->provisioningService->provision(
            organizerId: $organizerId,
            organizerName: $organizer->getName(),
            email: $organizer->getEmail(),
        );

        return $this->present($account, includeCredentials: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OrganizerPayramAccountDomainObject $account, bool $includeCredentials = false): array
    {
        $dashboardUrl = (string) config('services.payram.base_url', '');

        $payload = [
            'status' => $account->getStatus(),
            'project_name' => $account->getProjectName(),
            'member_email' => $account->getMemberEmail(),
            'external_platform_id' => $account->getExternalPlatformId(),
            'wallet_status' => $account->getWalletStatus(),
            'dashboard_url' => rtrim($dashboardUrl, '/'),
            'last_error' => $account->getLastError(),
        ];

        if ($includeCredentials && $account->getProvisionedPassword() !== null) {
            $payload['credentials'] = [
                'email' => $account->getMemberEmail(),
                'password' => $account->getProvisionedPassword(),
            ];
        }

        return $payload;
    }
}
