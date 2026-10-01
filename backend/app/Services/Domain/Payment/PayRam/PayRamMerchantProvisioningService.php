<?php

namespace HiEvents\Services\Domain\Payment\PayRam;

use HiEvents\DomainObjects\Generated\OrganizerPayramAccountDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerPayramAccountDomainObject;
use HiEvents\Exceptions\PayRam\PayRamApiException;
use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns an organizer into a PayRam merchant — the Stripe "platform creates the
 * connected account" half of onboarding. Everything here happens behind the
 * scenes when they switch crypto on; they only ever see their credentials.
 *
 * Wallet setup is deliberately NOT part of this: the organizer does that in
 * their own PayRam dashboard, which is where they get to keep control of their
 * keys and their money.
 */
class PayRamMerchantProvisioningService
{
    public const STATUS_PROVISIONING = 'PROVISIONING';

    public const STATUS_READY = 'READY';

    public const STATUS_FAILED = 'FAILED';

    public const WALLET_NOT_CONFIGURED = 'NOT_CONFIGURED';

    public const WALLET_READY = 'READY';

    public function __construct(
        private readonly PayRamOperatorClient $operatorClient,
        private readonly OrganizerPayRamAccountsRepositoryInterface $accountsRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  string  $organizerName  shown as the merchant/project name in PayRam
     * @param  string  $email  their dashboard login — an address they control
     *
     * @throws PayRamApiException
     */
    public function provision(int $organizerId, string $organizerName, string $email): OrganizerPayramAccountDomainObject
    {
        $password = self::generatePassword();

        try {
            $projectId = $this->operatorClient->createProject($organizerName);
            $this->operatorClient->createMember($organizerName, $email, $password);
            $this->operatorClient->assignMemberRole($email, $projectId, 'project_admin');
            $apiKey = $this->operatorClient->createApiKey($projectId, sprintf('monno server key for %s', $organizerName));
        } catch (Throwable $exception) {
            $this->logger->error('PayRam merchant provisioning failed', [
                'organizer_id' => $organizerId,
                'error' => $exception->getMessage(),
            ]);

            $this->storeFailure($organizerId, $email, $exception->getMessage());

            throw $exception;
        }

        $attributes = [
            OrganizerPayramAccountDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            OrganizerPayramAccountDomainObjectAbstract::EXTERNAL_PLATFORM_ID => $projectId,
            OrganizerPayramAccountDomainObjectAbstract::PROJECT_NAME => $organizerName,
            OrganizerPayramAccountDomainObjectAbstract::MEMBER_EMAIL => $email,
            OrganizerPayramAccountDomainObjectAbstract::API_KEY => $apiKey,
            OrganizerPayramAccountDomainObjectAbstract::PROVISIONED_PASSWORD => $password,
            OrganizerPayramAccountDomainObjectAbstract::STATUS => self::STATUS_READY,
            OrganizerPayramAccountDomainObjectAbstract::WALLET_STATUS => self::WALLET_NOT_CONFIGURED,
            OrganizerPayramAccountDomainObjectAbstract::LAST_ERROR => null,
        ];

        // A retry after a failed attempt must update, not trip the unique key.
        $existing = $this->findForOrganizer($organizerId);
        if ($existing !== null) {
            $this->accountsRepository->updateFromArray($existing->getId(), $attributes);

            return $this->findForOrganizer($organizerId);
        }

        return $this->accountsRepository->create($attributes);
    }

    /**
     * Idempotent: returns the existing account instead of provisioning twice.
     */
    public function findForOrganizer(int $organizerId): ?OrganizerPayramAccountDomainObject
    {
        return $this->accountsRepository->findFirstWhere([
            OrganizerPayramAccountDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);
    }

    public static function generatePassword(): string
    {
        return 'P'.Str::random(20).'9!';
    }

    private function storeFailure(int $organizerId, string $email, string $error): void
    {
        $existing = $this->findForOrganizer($organizerId);

        $attributes = [
            OrganizerPayramAccountDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            OrganizerPayramAccountDomainObjectAbstract::MEMBER_EMAIL => $email,
            OrganizerPayramAccountDomainObjectAbstract::STATUS => self::STATUS_FAILED,
            OrganizerPayramAccountDomainObjectAbstract::LAST_ERROR => Str::limit($error, 1000),
        ];

        if ($existing !== null) {
            $this->accountsRepository->updateFromArray($existing->getId(), $attributes);

            return;
        }

        try {
            $this->accountsRepository->create($attributes);
        } catch (Throwable $bookkeepingError) {
            // Never let bookkeeping mask the real provisioning error.
            $this->logger->error('Could not record PayRam provisioning failure', [
                'organizer_id' => $organizerId,
                'error' => $bookkeepingError->getMessage(),
            ]);
        }
    }
}
