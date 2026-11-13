<?php

namespace HiEvents\Services\Infrastructure\Payment\PayRam;

use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Services\Domain\Payment\PayRam\PayRamMerchantProvisioningService;

/**
 * Verifies a PayRam delivery against every key that could have sent it: the
 * shared instance key plus each organizer's own project key.
 *
 * Webhook endpoints are registered per project on PayRam's side, so once that
 * ships each organizer's payments will be signed with their key. The body is
 * only trusted after it matches one of them in constant time — we never pick
 * an organizer first, because the signature is what tells us it is genuine.
 */
class PayRamWebhookVerifier
{
    public function __construct(
        private readonly PayRamConfigurationService $configuration,
        private readonly OrganizerPayRamAccountsRepositoryInterface $accountsRepository,
    ) {}

    public function verify(?string $rawBody, ?string $signatureHeader, ?string $apiKeyHeader = null): bool
    {
        if ($rawBody === null || $rawBody === '') {
            return false;
        }

        foreach ($this->candidateSecrets() as $secret) {
            if ($secret === '') {
                continue;
            }

            if ($signatureHeader !== null && $signatureHeader !== '') {
                if (hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $signatureHeader)) {
                    return true;
                }

                continue;
            }

            // Legacy deliveries carry the key itself instead of a signature.
            if ($apiKeyHeader !== null && $apiKeyHeader !== '' && hash_equals($secret, $apiKeyHeader)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function candidateSecrets(): array
    {
        $secrets = [$this->configuration->getWebhookSecret()];

        $organizerKeys = $this->accountsRepository->findWhereIn('status', [
            PayRamMerchantProvisioningService::STATUS_READY,
        ]);

        foreach ($organizerKeys as $account) {
            $key = $account->getApiKey();
            if ($key !== null && $key !== '') {
                $secrets[] = $key;
            }
        }

        return array_values(array_unique($secrets));
    }
}
