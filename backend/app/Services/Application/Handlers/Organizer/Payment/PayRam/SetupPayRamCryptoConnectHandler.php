<?php

namespace HiEvents\Services\Application\Handlers\Organizer\Payment\PayRam;

use HiEvents\DomainObjects\Generated\OrganizerPayramAccountDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerPayramAccountDomainObject;
use HiEvents\Exceptions\ValidationException;
use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Payment\PayRam\PayRamGatewayStatusService;
use HiEvents\Services\Domain\Payment\PayRam\PayRamMerchantProvisioningService;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Throwable;

class SetupPayRamCryptoConnectHandler
{
    public function __construct(
        private readonly PayRamMerchantProvisioningService $provisioningService,
        private readonly OrganizerPayRamAccountsRepositoryInterface $accountsRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly PayRamOperatorClient $operatorClient,
        private readonly PayRamGatewayStatusService $gatewayStatusService,
        private readonly GetOrProvisionPayRamAccountHandler $accountPresenter,
    ) {}

    /**
     * @param  array<string>  $currencies
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function handle(
        int $organizerId,
        string $walletAddress,
        array $currencies = [],
        ?string $tronWalletAddress = null,
        ?string $btcWalletAddress = null,
    ): array {
        $walletAddress = trim($walletAddress);
        if ($walletAddress === '' || ! preg_match('/^0x[a-fA-F0-9]{40}$/', $walletAddress)) {
            throw new ValidationException(__('Please enter a valid EVM wallet address (0x followed by 40 hex characters).'));
        }

        $account = $this->provisioningService->findForOrganizer($organizerId);

        if ($account === null || $account->getStatus() === PayRamMerchantProvisioningService::STATUS_FAILED) {
            $organizer = $this->organizerRepository->findById($organizerId);
            $account = $this->provisioningService->provision(
                organizerId: $organizerId,
                organizerName: $organizer->getName(),
                email: $organizer->getEmail(),
            );
        }

        // Keep PayRam project endpoints updated with return/cancel URLs
        if ($account->getExternalPlatformId()) {
            try {
                $this->operatorClient->updateProject(
                    projectId: $account->getExternalPlatformId(),
                    name: $account->getProjectName() ?? 'Organizer '.$organizerId,
                );
            } catch (Throwable $e) {
                logger()->warning('Could not update PayRam project endpoints during Crypto Connect setup', [
                    'organizer_id' => $organizerId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($currencies)) {
            $currencies = ['ETH', 'USDC', 'USDT', 'POL', 'BASE_ETH', 'BASE_USDC'];
        }

        $attributes = [
            OrganizerPayramAccountDomainObject::WALLET_ADDRESS => $walletAddress,
            OrganizerPayramAccountDomainObject::SUPPORTED_CURRENCIES => $currencies,
            OrganizerPayramAccountDomainObjectAbstract::STATUS => PayRamMerchantProvisioningService::STATUS_READY,
            OrganizerPayramAccountDomainObjectAbstract::LAST_ERROR => null,
        ];

        // The gateway is authoritative about whether a payout wallet is attached.
        // We record what it actually reports rather than assuming our saved
        // address took effect. If the gateway is unreachable we leave the previous
        // wallet status untouched instead of failing the organizer's setup.
        $gatewayStatus = $this->gatewayStatusService->forProject($account->getExternalPlatformId());

        if ($gatewayStatus['available']) {
            $attributes[OrganizerPayramAccountDomainObjectAbstract::WALLET_STATUS] =
                $gatewayStatus['cold_wallet_configured']
                    ? PayRamMerchantProvisioningService::WALLET_READY
                    : PayRamMerchantProvisioningService::WALLET_NOT_CONFIGURED;
        }

        $this->accountsRepository->updateFromArray($account->getId(), $attributes);

        return $this->accountPresenter->handle($organizerId, provision: false);
    }
}
