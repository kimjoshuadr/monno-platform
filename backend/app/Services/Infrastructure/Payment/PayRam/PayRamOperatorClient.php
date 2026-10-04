<?php

namespace HiEvents\Services\Infrastructure\Payment\PayRam;

use HiEvents\Exceptions\PayRam\PayRamApiException;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
     * PayRam enforces unique project names, and a name is not an identity: two
     * organizers can legitimately be called "GN Club". Adopting a project by
     * name would hand one organizer a project belonging to the other — and all
     * their money with it — so a clash is never resolved by looking a name up.
     *
     * Instead the caller may supply a fresh name and we try again. Names cannot
     * be reclaimed at PayRam (there is no delete endpoint), so the only way past
     * a taken name is a different one.
     *
     * @param  callable(int, string, string): string|null  $onNameTaken
     *                                                                   receives (attempt, rejectedName, reason) and returns the next name;
     *                                                                   returning null aborts
     *
     * @throws PayRamApiException
     */
    public function createProject(string $name, ?callable $onNameTaken = null, int $maxAttempts = 5): int
    {
        $attempt = 1;
        $current = $name;

        while (true) {
            try {
                $body = $this->request('post', '/api/v1/external-platform', [
                    'name' => $current,
                    'successEndpoint' => $this->returnUrl('/public/payram/return'),
                    'cancelEndpoint' => $this->returnUrl('/public/payram/cancel'),
                ], withToken: true);

                $projectId = (int) ($body['id'] ?? 0);
                if ($projectId <= 0) {
                    throw new PayRamApiException(__('PayRam did not return a project id.'));
                }

                return $projectId;
            } catch (PayRamApiException $exception) {
                $body = (string) $exception->rawBody;
                $retryable = str_contains($body, 'DUPLICATE_PROJECT_NAME')
                    || str_contains($body, 'Project name is invalid');

                if (! $retryable || $onNameTaken === null || $attempt >= $maxAttempts) {
                    throw $exception;
                }

                $attempt++;
                $next = $onNameTaken($attempt, $current, $exception->getMessage());

                if ($next === null || $next === '' || $next === $current) {
                    throw $exception;
                }

                $current = $next;
            }
        }
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
     * Push the organizer-owned profile fields onto their PayRam project.
     *
     * Only the fields PayRam actually accepts are sent — the name, the website,
     * and the support address that appears on its emails. The brand image is
     * uploaded separately (a JSON `logoPath` is ignored), so it is not handled
     * here.
     *
     * @throws PayRamApiException
     */
    public function updateProjectProfile(int $projectId, string $name, ?string $website = null, ?string $supportEmail = null): void
    {
        $payload = [
            'name' => $name,
            'successEndpoint' => $this->returnUrl('/public/payram/return'),
            'cancelEndpoint' => $this->returnUrl('/public/payram/cancel'),
        ];

        if ($website !== null) {
            $payload['website'] = $website;
        }

        if ($supportEmail !== null) {
            $payload['emailSendRequestFrom'] = $supportEmail;
            $payload['emailSendRequestReplyTo'] = $supportEmail;
        }

        $this->request('put', sprintf('/api/v1/external-platform/%d', $projectId), $payload, withToken: true);
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
     * Attach the shared operator hot wallet to a project.
     *
     * Every project needs a hot wallet for the networks it accepts — it pays the
     * gas that sweeps buyer funds to the organizer's cold wallet, and PayRam has
     * no fallback to a shared one. This is the step that stops funds stranding.
     *
     * The assignment is a *replace* (`PUT /wallets/{id}/projects` takes the full
     * list), so we read the current set first and append. A cache lock serialises
     * concurrent sign-ups so two organizers cannot lose each other's assignment.
     *
     * @throws PayRamApiException
     */
    public function assignHotWallet(int $projectId, int $hotWalletId): void
    {
        if ($projectId <= 0 || $hotWalletId <= 0) {
            return;
        }

        try {
            Cache::lock('payram:hot_wallet_assign:'.$hotWalletId, 15)
                ->block(10, fn () => $this->writeHotWalletProjects($projectId, $hotWalletId));
        } catch (LockTimeoutException) {
            // Could not take the lock in time: do the work anyway rather than
            // silently skip it. The repair command reconciles any lost update.
            $this->writeHotWalletProjects($projectId, $hotWalletId);
        }
    }

    /**
     * @throws PayRamApiException
     */
    private function writeHotWalletProjects(int $projectId, int $hotWalletId): void
    {
        $assigned = $this->assignedProjectIdsForHotWallet($hotWalletId);

        if (in_array($projectId, $assigned, true)) {
            return; // idempotent — already assigned
        }

        $assigned[] = $projectId;

        $this->request(
            'put',
            sprintf('/api/v1/wallets/%d/projects', $hotWalletId),
            ['projectIds' => array_values($assigned)],
            withToken: true,
        );
    }

    /**
     * The projects a wallet is currently attached to. There is no GET on the
     * assignment endpoint, so the assignable-projects list (which carries a
     * status per project) is the source.
     *
     * @return array<int, int>
     *
     * @throws PayRamApiException
     */
    private function assignedProjectIdsForHotWallet(int $hotWalletId): array
    {
        $body = $this->request(
            'get',
            sprintf('/api/v1/project/all/wallets/%d/assignable-projects', $hotWalletId),
            [],
            withToken: true,
        );

        $ids = [];
        foreach (($body['projects'] ?? []) as $project) {
            if (is_array($project)
                && ($project['status'] ?? null) === 'currently_assigned'
                && isset($project['projectID'])) {
                $ids[] = (int) $project['projectID'];
            }
        }

        return $ids;
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
