<?php

namespace Tests\Unit\Services\Infrastructure\User;

use HiEvents\Services\Infrastructure\User\EmailVerificationCodeService;
use Tests\TestCase;

/**
 * Driven against the real array cache store rather than a mocked Repository:
 * the failure this suite exists to catch was a type mismatch between the stored
 * value and the submitted value, which a hand-written mock happily hides by
 * returning strings from both sides.
 */
class EmailVerificationCodeServiceTest extends TestCase
{
    private EmailVerificationCodeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EmailVerificationCodeService(app('cache')->store('array'));
    }

    public function test_store_and_return_code_returns_a_five_digit_string(): void
    {
        $code = $this->service->storeAndReturnCode('test@example.com');

        $this->assertIsString($code);
        $this->assertMatchesRegularExpression('/^\d{5}$/', $code);
        $this->assertGreaterThanOrEqual(10000, (int) $code);
        $this->assertLessThanOrEqual(99999, (int) $code);
    }

    public function test_a_generated_code_is_accepted_verbatim(): void
    {
        $email = 'test@example.com';
        $code = $this->service->storeAndReturnCode($email);

        // The regression: the code was cached as an int while the request
        // carries a string, and `!==` rejected every valid submission.
        $this->assertSame(
            EmailVerificationCodeService::OUTCOME_OK,
            $this->service->attempt($email, $code),
        );

        // Consuming it must not leave it reusable.
        $this->assertSame(
            EmailVerificationCodeService::OUTCOME_EXPIRED,
            $this->service->attempt($email, $code),
        );
    }

    public function test_an_integer_valued_cached_code_is_accepted(): void
    {
        // A cache backend that round-trips the value back as an int must not
        // re-introduce the mismatch, either.
        app('cache')->store('array')->put('email_verification_code:test@example.com', 12345, now()->addMinutes(30));

        $this->assertSame(
            EmailVerificationCodeService::OUTCOME_OK,
            $this->service->attempt('test@example.com', '12345'),
        );
    }

    public function test_a_wrong_code_is_rejected_and_counts_an_attempt(): void
    {
        $email = 'test@example.com';
        $code = $this->service->storeAndReturnCode($email);

        $this->assertSame(
            EmailVerificationCodeService::OUTCOME_MISMATCH,
            $this->service->attempt($email, '00000'),
        );
        $this->assertSame(
            EmailVerificationCodeService::OUTCOME_MISMATCH,
            $this->service->attempt($email, '00000'),
        );

        // Two misses must not lock out the real code early.
        $this->assertSame(
            EmailVerificationCodeService::OUTCOME_OK,
            $this->service->attempt($email, $code),
        );
    }

    public function test_the_code_is_burned_once_the_guess_budget_is_spent(): void
    {
        $email = 'test@example.com';
        $code = $this->service->storeAndReturnCode($email);
        $maxAttempts = (int) config('app.email_verification_max_attempts');

        for ($i = 1; $i <= $maxAttempts; $i++) {
            $this->assertSame(EmailVerificationCodeService::OUTCOME_MISMATCH, $this->service->attempt($email, '00000'));
        }

        // Out of budget: even the genuine code is refused now...
        $this->assertSame(
            EmailVerificationCodeService::OUTCOME_EXHAUSTED,
            $this->service->attempt($email, $code),
        );

        // ...and the code is deleted rather than left in cache for a patient
        // brute force, so what follows reads as expired, not as a live target.
        $this->assertSame(
            EmailVerificationCodeService::OUTCOME_EXPIRED,
            $this->service->attempt($email, $code),
        );
    }

    public function test_an_expired_code_reads_as_expired(): void
    {
        $email = 'test@example.com';
        $code = $this->service->storeAndReturnCode($email);

        $this->travel(((int) config('app.email_verification_code_ttl_minutes')) + 1)->minutes();

        $this->assertSame(
            EmailVerificationCodeService::OUTCOME_EXPIRED,
            $this->service->attempt($email, $code),
        );
    }

    public function test_a_code_that_was_never_issued_reads_as_expired(): void
    {
        $this->assertSame(
            EmailVerificationCodeService::OUTCOME_EXPIRED,
            $this->service->attempt('nobody@example.com', '12345'),
        );
    }

    public function test_a_consumed_code_cannot_be_replayed(): void
    {
        $email = 'test@example.com';
        $code = $this->service->storeAndReturnCode($email);

        $this->assertSame(EmailVerificationCodeService::OUTCOME_OK, $this->service->attempt($email, $code));
        $this->assertSame(EmailVerificationCodeService::OUTCOME_EXPIRED, $this->service->attempt($email, $code));
    }

    public function test_storing_a_new_code_resets_the_guess_budget(): void
    {
        $email = 'test@example.com';
        $this->service->storeAndReturnCode($email);

        for ($i = 1; $i <= (int) config('app.email_verification_max_attempts'); $i++) {
            $this->service->attempt($email, '00000');
        }

        $newCode = $this->service->storeAndReturnCode($email);

        $this->assertSame(EmailVerificationCodeService::OUTCOME_OK, $this->service->attempt($email, $newCode));
    }

    public function test_codes_for_different_addresses_are_independent(): void
    {
        $codeOne = $this->service->storeAndReturnCode('one@example.com');
        $codeTwo = $this->service->storeAndReturnCode('two@example.com');

        $this->assertSame(EmailVerificationCodeService::OUTCOME_OK, $this->service->attempt('one@example.com', $codeOne));
        // Consuming one address's code must not touch the other's.
        $this->assertSame(EmailVerificationCodeService::OUTCOME_OK, $this->service->attempt('two@example.com', $codeTwo));
    }

    public function test_verify_code_is_a_pass_fail_view_of_attempt(): void
    {
        $email = 'test@example.com';
        $code = $this->service->storeAndReturnCode($email);

        $this->assertFalse($this->service->verifyCode($email, '99999'));
        $this->assertTrue($this->service->verifyCode($email, $code));
    }
}
