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
        // PayRam enforces unique project names, and a name is not an identity:
        // two organizers can legitimately be called "GN Club". Adopting a
        // project by name would hand one organizer a project belonging to the
        // other — and all their money with it. So the project name we send is
        // always made unique for this merchant, and a name clash is never used
        // as a signal that a project is ours.
        $body = $this->request('post', '/api/v1/external-platform', [
            'name' => $name,
            'successEndpoint' => $this->returnUrl('/public/payram/return'),
            'cancelEndpoint' => $this->returnUrl('/public/payram/cancel'),
        ], withToken: true);

        $projectId = (int) ($body['id'] ?? 0);
        if ($projectId <= 0) {
            throw new PayRamApiException(__('PayRam did not return a project id.'));
        }

        return $projectId;
    }

    /**
     * @throws PayRamApiException
     */
    public function updateProject(int $projectId, string $name, ?string $successEndpoint = null, ?string $cancelEndpoint = null): void
    {
        $this->request('put', sprintf('/api/v1/external-platform/%d', $projectId), [
            'name' => $name,
            'successEndpoint' => $successEndpoint ?? $this->returnUrl('/public/payram/return'),
            'cancelEndpoint' => $cancelEndpoint ?? $this->returnUrl('/public/payram/cancel'),
        ], withToken: true);
    }

    /**
     * Where PayRam should send a buyer back to. Built from the public API base,
     * not APP_URL, because the deployed app sits behind an /api prefix.
     */
    private function returnUrl(string $path): string
    {
        return rtrim((string) config('app.api_public_url'), '/').$path;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PayRamApiException
     */
    public function signInMember(string $email, string $password, bool $autoClearResetRequired = true): array
    {
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

        if ($response->failed()) {
            throw new PayRamApiException(__('Could not sign in member to PayRam.'), $response->status(), $response->body());
        }

        $session = (array) $response->json();

        // If PayRam flags this member as needing a first-login password reset,
        // clear it proactively on the gateway so subsequent API calls don't get
        // blocked with RESET_PASSWORD_REQUIRED (HTTP 403).
        if ($autoClearResetRequired && ! empty($session['resetPasswordRequired'])) {
            $token = (string) ($session['accessToken'] ?? '');
            if ($token !== '') {
                try {
                    $this->changeMemberPassword($token, $password, $password);

                    return $this->signInMember($email, $password, autoClearResetRequired: false);
                } catch (\Throwable $exception) {
                    logger()->warning('Failed to auto-clear PayRam member resetPasswordRequired', [
                        'email' => $email,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $session;
    }

    /**
     * Changes a member's password using their authenticated session.
     * Used to clear PayRam's forced first-login password reset so the
     * organizer lands directly in the console without seeing a password prompt
     * or getting blocked with RESET_PASSWORD_REQUIRED (HTTP 403).
     *
     * @throws PayRamApiException
     */
    public function changeMemberPassword(string $accessToken, string $oldPassword, string $newPassword): void
    {
        try {
            $response = Http::withOptions([
                'timeout' => $this->configuration->getTimeout(),
                'connect_timeout' => 10,
            ])->withToken($accessToken)->asJson()->post($this->configuration->getBaseUrl().'/api/v1/member/change-password', [
                'oldPassword' => $oldPassword,
                'password' => $newPassword,
            ]);
        } catch (ConnectionException $exception) {
            throw new PayRamApiException(__('Could not reach the payment gateway.'), null, $exception->getMessage());
        }

        if ($response->failed()) {
            throw new PayRamApiException(
                (string) ($response->json('error.message') ?? $response->json('message') ?? __('Could not change member password.')),
                $response->status(),
                $response->body(),
            );
        }
    }

    /**
     * Creates the organizer's own dashboard login. PayRam marks it as needing a
     * password reset, which Monno clears upon first sign-in / SSO so organizers
     * land seamlessly in their console without a manual password prompt.
     *
     * @throws PayRamApiException
     */
    public function createMember(string $name, string $email, string $password): void
    {
        try {
            $this->request('post', '/api/v1/member', [
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ], withToken: true);
        } catch (PayRamApiException $exception) {
            // PayRam answers a duplicate email with HTTP 500 and an ALREADY_EXIST
            // body. That is a resume, not a failure: a previous attempt already
            // created this member.
            $raw = (string) $exception->rawBody;
            if (! str_contains($raw, 'ALREADY_EXIST') && ! str_contains(strtolower($raw), 'already exist')) {
                throw $exception;
            }
        }
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
     * The upstream project record, including the return/cancel endpoints PayRam
     * will send a buyer back to.
     *
     * @return array<string, mixed>
     *
     * @throws PayRamApiException
     */
    public function getProject(int $projectId): array
    {
        return $this->request('get', sprintf('/api/v1/external-platform/%d', $projectId), [], withToken: true);
    }

    /**
     * Every blockchain the gateway supports (code, family, network mode). The
     * source of truth for which currency codes a wizard selection may use.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PayRamApiException
     */
    public function getBlockchains(): array
    {
        return $this->requestList('/api/v1/blockchains');
    }

    /**
     * The gateway's catalogue of deposit-able blockchain/currency pairs.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PayRamApiException
     */
    public function getBlockchainCurrencies(): array
    {
        return $this->requestList('/api/v1/blockchain-currency');
    }

    /**
     * The wallets attached to a project (hot + deposit), for reconciling what
     * the gateway actually holds against what an organizer asked for.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PayRamApiException
     */
    public function getProjectWallets(int $projectId): array
    {
        return $this->requestList(sprintf('/api/v1/project/%d/wallets', $projectId));
    }

    /**
     * Per wallet/currency settlement status: cold-wallet readiness, what is
     * eligible to sweep, and the last sweep error. This is what turns "where is
     * my money?" into a concrete answer in the UI.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PayRamApiException
     */
    public function getProjectAddressBalances(int $projectId): array
    {
        return $this->requestList(sprintf('/api/v1/project/%d/addresses/balance', $projectId));
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws PayRamApiException
     */
    private function requestList(string $uri): array
    {
        $body = $this->request('get', $uri, [], withToken: true);

        // A list endpoint returns a JSON array; request() already coerced that to
        // an array, so anything without list shape is reported as empty.
        return array_is_list($body) ? $body : [];
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

            $url = $this->configuration->getBaseUrl().$uri;
            $response = match ($method) {
                'post' => $pending->asJson()->post($url, $payload),
                'put' => $pending->asJson()->put($url, $payload),
                'patch' => $pending->asJson()->patch($url, $payload),
                'delete' => $pending->asJson()->delete($url, $payload),
                default => $pending->get($url),
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

            $errorMessage = $response->json('error.message')
                ?? $response->json('message')
                ?? __('The payment gateway rejected the request.');

            throw new PayRamApiException(
                (string) $errorMessage,
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
