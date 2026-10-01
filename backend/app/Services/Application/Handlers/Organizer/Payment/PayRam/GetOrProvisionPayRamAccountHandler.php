<?php

namespace HiEvents\Services\Application\Handlers\Organizer\Payment\PayRam;

use HiEvents\DomainObjects\OrganizerPayramAccountDomainObject;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Payment\PayRam\PayRamGatewayStatusService;
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
        private readonly PayRamGatewayStatusService $gatewayStatusService,
    ) {}

    public const STATUS_NOT_CONNECTED = 'NOT_CONNECTED';

    /**
     * @param  bool  $provision  false = report only, so merely opening the settings
     *                           page never creates anything behind the user's back
     * @return array<string, mixed>
     */
    public function handle(int $organizerId, bool $provision = true): array
    {
        $existing = $this->provisioningService->findForOrganizer($organizerId);

        if ($existing !== null
            && $existing->getStatus() !== PayRamMerchantProvisioningService::STATUS_FAILED) {
            return $this->present($existing);
        }

        if (! $provision) {
            return ['status' => self::STATUS_NOT_CONNECTED, 'dashboard_url' => rtrim((string) config('services.payram.base_url'), '/')];
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
            'wallet_address' => $account->getWalletAddress(),
            'supported_currencies' => $account->getSupportedCurrencies(),
            'dashboard_url' => rtrim($dashboardUrl, '/'),
            'last_error' => $account->getLastError(),
            'gateway' => $this->gatewayStatusService->forProject($account->getExternalPlatformId()),
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
