<?php

namespace HiEvents\Services\Infrastructure\Payment\PayRam;

use HiEvents\Exceptions\PayRam\PayRamApiException;
use HiEvents\Services\Domain\Payment\PayRam\DTOs\PayRamPaymentSessionDTO;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class PayRamClient
{
    public function __construct(
        private readonly PayRamConfigurationService $configuration,
    ) {}

    /**
     * Create a hosted checkout session and hand the buyer the PayRam payment URL.
     *
     * @throws PayRamApiException
     */
    public function createPayment(
        string $customerEmail,
        string $customerId,
        float $amountInUsd,
        string $invoiceId,
        ?string $expireAt = null,
        ?string $currency = null,
        ?string $network = null,
    ): PayRamPaymentSessionDTO {
        $payload = array_filter([
            'customerEmail' => $customerEmail,
            'customerID' => $customerId,
            'amountInUSD' => $amountInUsd,
            'invoiceID' => $invoiceId,
            'expire' => $expireAt,
            'currency' => $currency,
            'network' => $network,
        ], static fn ($value) => $value !== null && $value !== '');

        $body = $this->request('post', '/api/v1/payment', $payload);

        $referenceId = (string) ($body['reference_id'] ?? '');
        $url = (string) ($body['url'] ?? '');

        if ($referenceId === '' || $url === '') {
            throw new PayRamApiException(__('PayRam did not return a payment reference.'));
        }

        return new PayRamPaymentSessionDTO(
            referenceId: $referenceId,
            checkoutUrl: $url,
            host: (string) ($body['host'] ?? ''),
        );
    }

    /**
     * Poll the current state of a payment (webhook-miss fallback / reconciliation).
     *
     * @return array<string, mixed>
     *
     * @throws PayRamApiException
     */
    public function getPaymentStatus(string $referenceId): array
    {
        return $this->request('get', '/api/v1/payment/reference/'.rawurlencode($referenceId));
    }

    /**
     * Live USD prices — stablecoins report price "1.0".
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PayRamApiException
     */
    public function getTicker(): array
    {
        $body = $this->request('get', '/api/v1/ticker');

        return is_array($body) ? $body : [];
    }

    /**
     * Verify a PayRam webhook delivery.
     *
     * Preferred: HMAC-SHA256 of the exact raw body, keyed with the project API
     * key, formatted "sha256=<hex>". Legacy: the API key itself, verbatim.
     */
    public function verifyWebhookSignature(?string $rawBody, ?string $signatureHeader, ?string $apiKeyHeader = null): bool
    {
        $secret = $this->configuration->getWebhookSecret();

        if ($rawBody === null || $rawBody === '' || $secret === '') {
            return false;
        }

        if ($signatureHeader !== null && $signatureHeader !== '') {
            return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $signatureHeader);
        }

        // Legacy deliveries carry the key itself instead of a signature.
        if ($apiKeyHeader !== null && $apiKeyHeader !== '') {
            return hash_equals($secret, $apiKeyHeader);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PayRamApiException
     */
    private function request(string $method, string $uri, array $payload = []): array
    {
        $this->configuration->assertCanCreatePayments();

        $headers = ['API-Key' => $this->configuration->getApiKey()];

        try {
            $pending = Http::withOptions([
                'timeout' => $this->configuration->getTimeout(),
                'connect_timeout' => 10,
            ])->withHeaders($headers);

            $response = match ($method) {
                'post' => $pending->asJson()->post($this->configuration->getBaseUrl().$uri, $payload),
                default => $pending->get($this->configuration->getBaseUrl().$uri),
            };
        } catch (ConnectionException $exception) {
            throw new PayRamApiException(__('Could not reach the payment gateway.'), null, $exception->getMessage());
        }

        if ($response->failed()) {
            throw new PayRamApiException(
                __('The payment gateway rejected the request.'),
                $response->status(),
                $response->body(),
            );
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}
