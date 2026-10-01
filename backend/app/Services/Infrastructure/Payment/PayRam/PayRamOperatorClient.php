<?php

namespace HiEvents\Services\Infrastructure\Payment\PayRam;

use HiEvents\Exceptions\PayRam\PayRamApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Admin-side calls to the PayRam instance, authenticated as the operator
 * (email/password JWT) rather than a project API key.
 *
 * These are the calls that make an organizer a merchant: create their project,
 * create their dashboard login, scope it to their project, mint their key.
 */
class PayRamOperatorClient
{
    private const TOKEN_CACHE_KEY = 'payram_operator_token';

    private const TOKEN_TTL_SECONDS = 1500; // JWTs are short-lived; renew early

    public function __construct(
        private readonly PayRamConfigurationService $configuration,
    ) {}

    /**
     * @throws PayRamApiException
     */
    public function createProject(string $name): int
    {
        $body = $this->request('post', '/api/v1/external-platform', ['name' => $name], withToken: true);

        $projectId = (int) ($body['id'] ?? 0);
        if ($projectId <= 0) {
            throw new PayRamApiException(__('PayRam did not return a project id.'));
        }

        return $projectId;
    }

    /**
     * Creates the organizer's own dashboard login. PayRam marks it as needing a
     * password reset, so they set their own password on first sign-in.
     *
     * @throws PayRamApiException
     */
    public function createMember(string $name, string $email, string $password): void
    {
        $this->request('post', '/api/v1/member', [
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], withToken: true);
    }

    /**
     * @throws PayRamApiException
     */
    public function assignMemberRole(string $memberEmail, int $platformId, string $roleName = 'project_admin'): void
    {
        $this->request('post', sprintf('/api/v1/member/%s/roles', rawurlencode($memberEmail)), [
            'isAdminRole' => false,
            'externalPlatformRoles' => [[
                'platformId' => $platformId,
                'roleName' => $roleName,
            ]],
        ], withToken: true);
    }

    /**
     * @throws PayRamApiException
     */
    public function createApiKey(int $projectId, string $description): string
    {
        $body = $this->request(
            'post',
            sprintf('/api/v1/external-platform/%d/api-key', $projectId),
            ['description' => $description],
            withToken: true,
        );

        $key = (string) ($body['key'] ?? '');
        if ($key === '') {
            throw new PayRamApiException(__('PayRam did not return an API key.'));
        }

        return $key;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PayRamApiException
     */
    private function request(string $method, string $uri, array $payload, bool $withToken): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($withToken) {
            $headers['Authorization'] = 'Bearer '.$this->token();
        }

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
            // A stale token should not fail provisioning — try once more.
            if ($response->status() === 401 && $withToken) {
                Cache::forget(self::TOKEN_CACHE_KEY);

                return $this->request($method, $uri, $payload, withToken: false);
            }

            throw new PayRamApiException(
                __('The payment gateway rejected the request.'),
                $response->status(),
                $response->body(),
            );
        }

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * @throws PayRamApiException
     */
    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $email = (string) config('services.payram.operator_email', '');
        $password = (string) config('services.payram.operator_password', '');

        if ($email === '' || $password === '') {
            throw new PayRamApiException(__('The payment gateway operator credentials are not configured.'));
        }

        try {
            $response = Http::withOptions([
                'timeout' => $this->configuration->getTimeout(),
                'connect_timeout' => 10,
            ])->asJson()->post($this->configuration->getBaseUrl().'/api/v1/signin', [
                'email' => $email,
                'password' => $password,
            ]);
        } catch (ConnectionException $exception) {
            throw new PayRamApiException(__('Could not reach the payment gateway.'), null, $exception->getMessage());
        }

        $token = (string) ($response->json('accessToken') ?? '');

        if ($response->failed() || $token === '') {
            throw new PayRamApiException(__('Could not sign in to the payment gateway.'), $response->status(), $response->body());
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, self::TOKEN_TTL_SECONDS);

        return $token;
    }
}
