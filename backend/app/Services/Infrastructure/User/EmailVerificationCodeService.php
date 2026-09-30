<?php

namespace HiEvents\Services\Infrastructure\User;

use Illuminate\Cache\Repository;

/**
 * Stores the 5-digit code an organizer must type during onboarding.
 *
 * Two things this has to get right, because getting them wrong means either a
 * lockout or a bypassable gate:
 *
 *  - The cached code and the submitted code must be compared as strings. The
 *    code is generated with random_int() and was stored as an int, so a strict
 *    `!==` against the string the request carries rejected every valid code —
 *    verification could never succeed. hash_equals() casts first, which also
 *    keeps the comparison timing-safe.
 *  - Guesses have to be bounded. The code lives in cache for 30 minutes and
 *    there are only 90,000 possibilities, so an unthrottled endpoint would walk
 *    it. Attempts are counted alongside the code; hitting the limit burns the
 *    code and forces a resend.
 */
class EmailVerificationCodeService
{
    public const OUTCOME_OK = 'ok';

    /** Nothing in cache: expired, never sent, or already consumed. */
    public const OUTCOME_EXPIRED = 'expired';

    /** Code still in cache but the guess budget is spent. */
    public const OUTCOME_EXHAUSTED = 'exhausted';

    /** A valid-looking attempt that simply does not match. */
    public const OUTCOME_MISMATCH = 'mismatch';

    public function __construct(
        private readonly Repository $cacheRepository,
    ) {}

    /**
     * @return string the freshly generated code, as it must be shown to the user
     */
    public function storeAndReturnCode(string $email): string
    {
        $code = (string) random_int(10000, 99999);
        $ttl = $this->ttlInMinutes();

        $this->cacheRepository->put($this->getCacheKey($email), $code, now()->addMinutes($ttl));
        $this->cacheRepository->put($this->getAttemptsKey($email), 0, now()->addMinutes($ttl));

        return $code;
    }

    /**
     * Consume one guess against the stored code.
     *
     * @return self::OUTCOME_*
     */
    public function attempt(string $email, string $code): string
    {
        $storedCode = $this->cacheRepository->get($this->getCacheKey($email));

        if ($storedCode === null) {
            return self::OUTCOME_EXPIRED;
        }

        $attempts = (int) ($this->cacheRepository->get($this->getAttemptsKey($email)) ?? 0);
        $maxAttempts = $this->maxAttempts();

        if ($attempts >= $maxAttempts) {
            $this->clear($email);

            return self::OUTCOME_EXHAUSTED;
        }

        if (! hash_equals((string) $storedCode, ltrim((string) $code, ' '))) {
            $this->cacheRepository->put(
                $this->getAttemptsKey($email),
                $attempts + 1,
                now()->addMinutes($this->ttlInMinutes()),
            );

            return self::OUTCOME_MISMATCH;
        }

        $this->clear($email);

        return self::OUTCOME_OK;
    }

    /**
     * Kept for callers (and tests) that only care about pass/fail.
     */
    public function verifyCode(string $email, string $code): bool
    {
        return $this->attempt($email, $code) === self::OUTCOME_OK;
    }

    public function clear(string $email): void
    {
        $this->cacheRepository->forget($this->getCacheKey($email));
        $this->cacheRepository->forget($this->getAttemptsKey($email));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('app.email_verification_max_attempts', 5));
    }

    private function ttlInMinutes(): int
    {
        return max(1, (int) config('app.email_verification_code_ttl_minutes', 30));
    }

    private function getCacheKey(string $email): string
    {
        return 'email_verification_code:'.$email;
    }

    private function getAttemptsKey(string $email): string
    {
        return 'email_verification_attempts:'.$email;
    }
}
