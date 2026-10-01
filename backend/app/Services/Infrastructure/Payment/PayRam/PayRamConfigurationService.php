<?php

namespace HiEvents\Services\Infrastructure\Payment\PayRam;

use HiEvents\Exceptions\PayRam\PayRamConfigurationException;

/**
 * Reads the PayRam gateway configuration (services.payram).
 *
 * PayRam is self-hosted at pay.monno.io; a single project API key authenticates
 * both the payment APIs and the webhook HMAC signature.
 */
readonly class PayRamConfigurationService
{
    private const CONFIG_KEY = 'services.payram';

    public function isEnabled(): bool
    {
        return (bool) config(self::CONFIG_KEY.'.enabled', false);
    }

    public function getBaseUrl(): string
    {
        return rtrim((string) config(self::CONFIG_KEY.'.base_url', ''), '/');
    }

    public function getApiKey(): string
    {
        return (string) config(self::CONFIG_KEY.'.api_key', '');
    }

    public function getWebhookSecret(): string
    {
        return (string) (config(self::CONFIG_KEY.'.webhook_secret') ?: $this->getApiKey());
    }

    public function getTimeout(): int
    {
        return (int) config(self::CONFIG_KEY.'.timeout', 20);
    }

    /**
     * @param  string|null  $providedKey  a per-organizer project key, when the
     *                                    merchant account has been provisioned
     *
     * @throws PayRamConfigurationException
     */
    public function assertCanCreatePayments(?string $providedKey = null): void
    {
        if (! $this->isEnabled()) {
            throw new PayRamConfigurationException(__('Crypto payments are not enabled.'));
        }

        if ($this->getBaseUrl() === '') {
            throw new PayRamConfigurationException(__('Crypto payments are not configured. Please contact the organizer.'));
        }

        if (($providedKey ?? '') === '' && $this->getApiKey() === '') {
            throw new PayRamConfigurationException(__('Crypto payments are not configured. Please contact the organizer.'));
        }
    }
}
