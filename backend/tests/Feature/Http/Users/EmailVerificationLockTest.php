<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Users;

use HiEvents\Mail\Account\EmailConfirmationCodeEmail;
use HiEvents\Models\User;
use HiEvents\Services\Infrastructure\User\EmailVerificationCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * The lock end to end: what an unverified organizer can and cannot reach, how
 * the code is spent, and the two mail ceilings that keep a resend loop from
 * turning into a mail bomb.
 *
 * Every assertion here exists because the feature is a lock — a lock that lets
 * the wrong thing through is worse than no lock, and a lock that jams is a
 * support ticket.
 */
class EmailVerificationLockTest extends TestCase
{
    use RefreshDatabase;

    private const ME_ROUTE = '/users/me';
    private const ORGANIZERS_ROUTE = '/organizers';

    protected function setUp(): void
    {
        parent::setUp();

        // On under test by default; the escape-hatch test switches it back off.
        config()->set('app.require_email_verification', true);
        config()->set('app.email_verification_max_attempts', 5);
        config()->set('app.email_verification_resend_daily_limit', 10);
        Cache::flush();
    }

    // --- the lock -----------------------------------------------------------

    public function test_an_unverified_organizer_cannot_reach_the_dashboard(): void
    {
        [, , $headers] = $this->organizer(verified: false);

        $response = $this->withHeaders($headers)->getJson(self::ORGANIZERS_ROUTE);

        $response->assertStatus(403);
        $response->assertJson([
            'code' => 'email_not_verified',
        ]);
    }

    public function test_an_unverified_organizer_cannot_reach_events(): void
    {
        [, , $headers] = $this->organizer(verified: false);

        $this->withHeaders($headers)->getJson('/events')->assertStatus(403);
    }

    public function test_me_stays_reachable_so_the_verify_screen_can_render(): void
    {
        [$user, , $headers] = $this->organizer(verified: false);

        $response = $this->withHeaders($headers)->getJson(self::ME_ROUTE);

        $response->assertOk();
        $response->assertJsonPath('data.is_email_verified', false);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_the_verify_and_resend_endpoints_stay_reachable_while_locked(): void
    {
        [$user, , $headers] = $this->organizer(verified: false);

        $this->withHeaders($headers)
            ->postJson(sprintf('/users/%d/confirm-email-with-code', $user->id), ['code' => '00000'])
            ->assertStatus(422); // reached the handler and failed on the code, not the lock

        $this->withHeaders($headers)
            ->postJson(sprintf('/users/%d/resend-email-confirmation', $user->id))
            ->assertNoContent();
    }

    public function test_a_verified_organizer_is_unaffected(): void
    {
        [, , $headers] = $this->organizer(verified: true);

        $this->withHeaders($headers)->getJson(self::ORGANIZERS_ROUTE)->assertOk();
    }

    public function test_a_ticket_buyer_is_never_gated(): void
    {
        // Buyers belong to no account: no account_id claim, no organizer
        // surface, and their address is confirmed when they register.
        $buyer = User::factory()->create();
        $token = JWTAuth::fromUser($buyer);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson(self::ME_ROUTE)
            ->assertOk();
    }

    public function test_the_lock_can_be_switched_off_if_the_sending_domain_fails(): void
    {
        config()->set('app.require_email_verification', false);

        [, , $headers] = $this->organizer(verified: false);

        $this->withHeaders($headers)->getJson(self::ORGANIZERS_ROUTE)->assertOk();
    }

    // --- spending the code --------------------------------------------------

    public function test_the_correct_code_verifies_and_unlocks(): void
    {
        Mail::fake();
        [$user, , $headers] = $this->organizer(verified: false);
        $code = app(EmailVerificationCodeService::class)->storeAndReturnCode($user->email);

        $this->withHeaders($headers)
            ->postJson(sprintf('/users/%d/confirm-email-with-code', $user->id), ['code' => $code])
            ->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);

        // The guard caches its resolved user for the life of the app instance,
        // and a single test reuses one app across requests — so it would still
        // be holding the pre-verification row. Production rebuilds it per
        // request; drop it here for the same reason.
        $this->app['auth']->forgetGuards();

        $this->withHeaders($headers)->getJson(self::ORGANIZERS_ROUTE)->assertOk();
    }

    public function test_a_wrong_code_reads_as_invalid_and_leaves_the_user_locked(): void
    {
        [$user, , $headers] = $this->organizer(verified: false);
        app(EmailVerificationCodeService::class)->storeAndReturnCode($user->email);

        $response = $this->withHeaders($headers)
            ->postJson(sprintf('/users/%d/confirm-email-with-code', $user->id), ['code' => '00000']);

        $response->assertStatus(422);
        $this->assertStringContainsString('invalid', strtolower($response->json('message')));
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_exhausting_the_guess_budget_demands_a_fresh_code(): void
    {
        [$user, , $headers] = $this->organizer(verified: false);
        $code = app(EmailVerificationCodeService::class)->storeAndReturnCode($user->email);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withHeaders($headers)
                ->postJson(sprintf('/users/%d/confirm-email-with-code', $user->id), ['code' => '00000'])
                ->assertStatus(422);
        }

        // Out of budget: even the genuine code is refused now.
        $response = $this->withHeaders($headers)
            ->postJson(sprintf('/users/%d/confirm-email-with-code', $user->id), ['code' => $code]);

        $response->assertStatus(422);
        $this->assertStringContainsString('too many times', strtolower($response->json('message')));
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_code_says_expired_rather_than_invalid(): void
    {
        [$user, , $headers] = $this->organizer(verified: false);
        app(EmailVerificationCodeService::class)->storeAndReturnCode($user->email);

        $this->travel((int) config('app.email_verification_code_ttl_minutes') + 1)->minutes();

        $response = $this->withHeaders($headers)
            ->postJson(sprintf('/users/%d/confirm-email-with-code', $user->id), ['code' => '12345']);

        $response->assertStatus(422);
        $this->assertStringContainsString('expired', strtolower($response->json('message')));
    }

    public function test_submitting_after_verification_in_another_tab_succeeds(): void
    {
        // The pin auto-submits after a debounce, so this happens whenever a user
        // finishes in a second tab: it must not show up as a red failure.
        [$user, , $headers] = $this->organizer(verified: true);

        $this->withHeaders($headers)
            ->postJson(sprintf('/users/%d/confirm-email-with-code', $user->id), ['code' => '00000'])
            ->assertOk();
    }

    // --- resend ceilings ----------------------------------------------------

    public function test_resend_has_a_cooldown(): void
    {
        Mail::fake();
        [$user, , $headers] = $this->organizer(verified: false);

        $this->withHeaders($headers)
            ->postJson(sprintf('/users/%d/resend-email-confirmation', $user->id))
            ->assertNoContent();

        $blocked = $this->withHeaders($headers)
            ->postJson(sprintf('/users/%d/resend-email-confirmation', $user->id));

        $blocked->assertStatus(429);
        $this->assertStringContainsString('seconds', strtolower($blocked->json('message')));
        Mail::assertQueued(EmailConfirmationCodeEmail::class, 1);
    }

    public function test_resend_holds_a_daily_quota(): void
    {
        Mail::fake();
        config()->set('app.email_verification_resend_daily_limit', 3);
        [$user, , $headers] = $this->organizer(verified: false);
        $route = sprintf('/users/%d/resend-email-confirmation', $user->id);

        for ($sent = 1; $sent <= 3; $sent++) {
            $this->withHeaders($headers)->postJson($route)->assertNoContent();
            $this->travel(31)->seconds(); // step over the cooldown, stay inside 24h
        }

        Mail::assertQueued(EmailConfirmationCodeEmail::class, 3);

        $blocked = $this->withHeaders($headers)->postJson($route);
        $blocked->assertStatus(429);
        $this->assertStringContainsString('already requested', strtolower($blocked->json('message')));
        Mail::assertQueued(EmailConfirmationCodeEmail::class, 3); // nothing new went out
    }

    public function test_an_already_verified_user_gets_the_link_flow_not_a_fresh_code(): void
    {
        Mail::fake();
        [$user, , $headers] = $this->organizer(verified: true);

        $this->withHeaders($headers)
            ->postJson(sprintf('/users/%d/resend-email-confirmation', $user->id))
            ->assertNoContent();

        Mail::assertNotQueued(EmailConfirmationCodeEmail::class);
        $this->assertNull(cache()->get('email_verification_code:'.$user->email));
    }

    // --- helpers ------------------------------------------------------------

    /**
     * @return array{0: User, 1: int, 2: array<string, string>}
     */
    private function organizer(bool $verified): array
    {
        // locale pinned to English: the API translates its messages from the
        // user's locale, and the factory otherwise picks one at random, which
        // would make every copy assertion in this file a coin toss.
        $factory = User::factory()->state(['locale' => 'en'])->withAccount();

        $user = ($verified ? $factory : $factory->unverified())->create();
        $accountId = $user->accounts()->first()->id;
        $token = JWTAuth::claims(['account_id' => $accountId])->fromUser($user);

        return [$user, $accountId, ['Authorization' => 'Bearer '.$token]];
    }
}
