<?php

namespace Tests\Feature\Services\Domain\Payment\PayRam;

use HiEvents\DomainObjects\Generated\OrganizerPayramAccountDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerPayramAccountDomainObject;
use HiEvents\Exceptions\PayRam\PayRamApiException;
use HiEvents\Models\Account;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\OrganizerPayRamAccountsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\Payment\PayRam\GetOrProvisionPayRamAccountHandler;
use HiEvents\Services\Domain\Payment\PayRam\PayRamMerchantProvisioningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * An organizer becomes a PayRam merchant behind the scenes, and from then on
 * their payments are created under their own project key — never monno's.
 */
class PayRamMerchantProvisioningTest extends TestCase
{
    use DatabaseTransactions;

    private const ORGANIZER_KEY = 'organizer-project-key-abc12345';

    private const OPERATOR_EMAIL = 'admin@monno.io';

    private int $accountId;

    private int $organizerId;

    private int $eventId;

    private string $orderShortId;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payram.enabled' => true,
            'services.payram.base_url' => 'https://pay.test',
            'services.payram.api_key' => 'monno-shared-key',
            'services.payram.webhook_secret' => 'monno-shared-key',
            'services.payram.operator_email' => self::OPERATOR_EMAIL,
            'services.payram.operator_password' => 'operator-password',
            'mail.from.address' => 'no-reply@monno.io',
        ]);

        Bus::fake();

        $user = User::factory()->create();
        $this->accountId = Account::factory()->create()->id;

        $this->organizerId = DB::table('organizers')->insertGetId([
            'account_id' => $this->accountId,
            'name' => 'Acme Run',
            'email' => 'organizer@example.com',
            'timezone' => 'Asia/Manila',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->eventId = DB::table('events')->insertGetId([
            'title' => 'Provisioning test event',
            'short_id' => 'EVTMERCHANT01',
            'account_id' => $this->accountId,
            'user_id' => $user->id,
            'organizer_id' => $this->organizerId,
            'currency' => 'USD',
            'status' => 'LIVE',
            'start_date' => now()->addDay(),
            'end_date' => now()->addDay()->addHours(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('event_settings')->insert([
            'event_id' => $this->eventId,
            'payment_providers' => json_encode(['OFFLINE', 'PAYRAM']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->sessionId = 'merchant-session-'.uniqid();
        $this->orderShortId = 'oMERCHANT01';

        DB::table('orders')->insert([
            'event_id' => $this->eventId,
            'short_id' => $this->orderShortId,
            'public_id' => (string) random_int(100000, 999999),
            'currency' => 'USD',
            'status' => 'RESERVED',
            'payment_status' => 'AWAITING_PAYMENT',
            'total_gross' => 25.00,
            'total_before_additions' => 25.00,
            'total_refunded' => 0,
            'reserved_until' => now()->addMinutes(30),
            'email' => 'buyer@example.com',
            'session_id' => $this->sessionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function provisioningService(): PayRamMerchantProvisioningService
    {
        return app(PayRamMerchantProvisioningService::class);
    }

    private function fakeSuccessfulProvisioning(): void
    {
        Http::fake([
            '*signin*' => Http::response(['accessToken' => 'operator-jwt-token']),
            '*external-platform/42/api-key*' => Http::response(['id' => 7, 'key' => self::ORGANIZER_KEY]),
            '*api/v1/external-platform*' => Http::response(['id' => 42, 'name' => 'Acme Run']),
            '*member*' => Http::response(['id' => 11]),
        ]);
    }

    public function test_it_provisions_a_merchant_project_login_role_and_api_key(): void
    {
        $this->fakeSuccessfulProvisioning();

        $account = $this->provisioningService()->provision($this->organizerId, 'Acme Run', 'organizer@example.com');

        $this->assertSame(PayRamMerchantProvisioningService::STATUS_READY, $account->getStatus());
        $this->assertSame(42, $account->getExternalPlatformId());
        $this->assertSame(self::ORGANIZER_KEY, $account->getApiKey());
        $this->assertSame('organizer@example.com', $account->getMemberEmail());
        $this->assertNotEmpty($account->getProvisionedPassword(), 'They need credentials to reach their dashboard.');
        $this->assertSame(PayRamMerchantProvisioningService::WALLET_NOT_CONFIGURED, $account->getWalletStatus());

        // the sequence the dashboard itself performs
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/signin')
            && $request['email'] === self::OPERATOR_EMAIL);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/external-platform')
            && $request['name'] === sprintf('Acme Run (%d)', $this->organizerId));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/member')
            && ! str_contains($request->url(), 'roles')
            && $request['email'] === 'organizer@example.com');
        // the member email is url-encoded in the path
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/member/organizer%40example.com/roles')
            && $request['isAdminRole'] === false
            && $request['externalPlatformRoles'][0]['roleName'] === 'project_admin'
            && $request['externalPlatformRoles'][0]['platformId'] === 42);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/external-platform/42/api-key'));
    }

    public function test_it_attaches_the_shared_hot_wallet_when_configured(): void
    {
        config(['services.payram.hot_wallet_id' => 5]);

        Http::fake([
            '*signin*' => Http::response(['accessToken' => 'operator-jwt-token']),
            '*external-platform/42/api-key*' => Http::response(['id' => 7, 'key' => self::ORGANIZER_KEY]),
            '*api/v1/external-platform*' => Http::response(['id' => 42, 'name' => 'Acme Run']),
            '*member*' => Http::response(['id' => 11]),
            '*project/all/wallets/5/assignable-projects*' => Http::response([
                'projects' => [['projectID' => 2, 'status' => 'currently_assigned']],
            ]),
            '*wallets/5/projects*' => Http::response(['success' => true]),
        ]);

        $this->provisioningService()->provision($this->organizerId, 'Acme Run', 'organizer@example.com');

        // The new project is appended to the wallet's existing assignments, so a
        // shared hot wallet can serve every merchant.
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/api/v1/wallets/5/projects')
            && $request['projectIds'] === [2, 42]);
    }

    public function test_a_failed_hot_wallet_assignment_does_not_block_provisioning(): void
    {
        config(['services.payram.hot_wallet_id' => 5]);

        Http::fake([
            '*signin*' => Http::response(['accessToken' => 'operator-jwt-token']),
            '*external-platform/42/api-key*' => Http::response(['id' => 7, 'key' => self::ORGANIZER_KEY]),
            '*api/v1/external-platform*' => Http::response(['id' => 42, 'name' => 'Acme Run']),
            '*member*' => Http::response(['id' => 11]),
            '*project/all/wallets/5/assignable-projects*' => Http::response(['projects' => []]),
            '*wallets/5/projects*' => Http::response(['error' => ['code' => 'BOOM']], 500),
        ]);

        $account = $this->provisioningService()->provision($this->organizerId, 'Acme Run', 'organizer@example.com');

        // A missing assignment is recoverable by the repair command; refusing to
        // provision would leave the organizer unable to accept crypto at all.
        $this->assertSame(PayRamMerchantProvisioningService::STATUS_READY, $account->getStatus());
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $this->fakeSuccessfulProvisioning();
        $this->provisioningService()->provision($this->organizerId, 'Acme Run', 'organizer@example.com');

        $raw = DB::table('organizer_payram_accounts')
            ->where('organizer_id', $this->organizerId)
            ->value('api_key');

        $this->assertNotSame(self::ORGANIZER_KEY, $raw, 'The key must not sit in the database in plain text.');
        $this->assertStringStartsWith('eyJ', (string) $raw, 'Encrypted values are stored as ciphertext.');

        $decrypted = app(OrganizerPayRamAccountsRepositoryInterface::class)
            ->findFirstWhere([OrganizerPayramAccountDomainObjectAbstract::ORGANIZER_ID => $this->organizerId])
            ->getApiKey();

        $this->assertSame(self::ORGANIZER_KEY, $decrypted);
    }

    public function test_a_failed_provisioning_is_recorded_and_the_error_rethrown(): void
    {
        Http::fake([
            '*signin*' => Http::response(['accessToken' => 'operator-jwt-token']),
            '*api/v1/external-platform*' => Http::response(['error' => ['code' => 'BOOM']], 400),
        ]);

        try {
            $this->provisioningService()->provision($this->organizerId, 'Acme Run', 'organizer@example.com');
            $this->fail('Expected provisioning to fail.');
        } catch (PayRamApiException) {
            // expected
        }

        $row = DB::table('organizer_payram_accounts')->where('organizer_id', $this->organizerId)->first();
        $this->assertNotNull($row, 'A failure must still be recorded so the organizer sees why.');
        $this->assertSame('FAILED', $row->status);
        $this->assertNotNull($row->last_error);
    }

    public function test_checkout_creates_the_payment_under_the_organizers_key(): void
    {
        // they are already a merchant
        app(OrganizerPayRamAccountsRepositoryInterface::class)->create([
            OrganizerPayramAccountDomainObjectAbstract::ORGANIZER_ID => $this->organizerId,
            OrganizerPayramAccountDomainObjectAbstract::EXTERNAL_PLATFORM_ID => 42,
            OrganizerPayramAccountDomainObjectAbstract::PROJECT_NAME => 'Acme Run',
            OrganizerPayramAccountDomainObjectAbstract::MEMBER_EMAIL => 'organizer@example.com',
            OrganizerPayramAccountDomainObjectAbstract::API_KEY => self::ORGANIZER_KEY,
            OrganizerPayramAccountDomainObjectAbstract::STATUS => PayRamMerchantProvisioningService::STATUS_READY,
        ]);

        Http::fake(['*' => Http::response([
            'host' => 'https://pay.test',
            'reference_id' => 'their-ref-1',
            'url' => 'https://pay.test/payments?reference_id=their-ref-1',
        ])]);

        $response = $this->postJson(sprintf(
            '/public/events/%d/order/%s/payram/payment?session_identifier=%s',
            $this->eventId,
            $this->orderShortId,
            $this->sessionId,
        ));

        $response->assertOk();
        $this->assertSame('their-ref-1', $response->json('reference_id'));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/payment')
            && $request->hasHeader('API-Key', self::ORGANIZER_KEY));

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/v1/payment')
            && $request->hasHeader('API-Key', 'monno-shared-key'));
    }

    public function test_it_is_idempotent_so_re_enabling_crypto_never_provisions_twice(): void
    {
        $this->fakeSuccessfulProvisioning();
        $service = $this->provisioningService();

        $service->provision($this->organizerId, 'Acme Run', 'organizer@example.com');
        $existing = $service->findForOrganizer($this->organizerId);

        $this->assertInstanceOf(OrganizerPayramAccountDomainObject::class, $existing);
        $this->assertSame(
            1,
            DB::table('organizer_payram_accounts')->where('organizer_id', $this->organizerId)->count(),
        );
    }

    public function test_credentials_are_handed_over_exactly_once(): void
    {
        $this->fakeSuccessfulProvisioning();
        $handler = app(GetOrProvisionPayRamAccountHandler::class);

        $first = $handler->handle($this->organizerId);

        $this->assertArrayHasKey('credentials', $first, 'The first response carries their dashboard password.');
        $this->assertSame('organizer@example.com', $first['credentials']['email']);
        $this->assertNotEmpty($first['credentials']['password']);

        $second = $handler->handle($this->organizerId);

        $this->assertArrayNotHasKey('credentials', $second, 'It must not be readable a second time.');
        $this->assertSame('READY', $second['status']);
        $this->assertNotNull($second['dashboard_url']);

        // One provisioning run (signin, project, member, roles, api-key) plus a
        // gateway status read on each present(). Two presents means two reads:
        // only a *successful* status is cached, and in this fake every status
        // call is answered, so the second read is a real call rather than the
        // cached first one.
        Http::assertSentCount(7);
    }

    public function test_a_failed_account_is_retried_and_then_returns_credentials(): void
    {
        // Http::fake() appends, so one stateful fake plays both attempts:
        // the first project creation is rejected, the second succeeds.
        $projectAttempts = 0;
        Http::fake(function ($request) use (&$projectAttempts) {
            $url = $request->url();

            if (str_ends_with($url, '/api/v1/signin')) {
                return Http::response(['accessToken' => 'operator-jwt-token']);
            }

            if (str_contains($url, '/api-key')) {
                return Http::response(['id' => 7, 'key' => self::ORGANIZER_KEY]);
            }

            if (str_ends_with($url, '/api/v1/external-platform')) {
                $projectAttempts++;

                return $projectAttempts === 1
                    ? Http::response(['error' => ['code' => 'BOOM']], 400)
                    : Http::response(['id' => 42, 'name' => 'Acme Run']);
            }

            return Http::response(['id' => 11]);
        });

        $handler = app(GetOrProvisionPayRamAccountHandler::class);

        try {
            $handler->handle($this->organizerId);
            $this->fail('Expected the first attempt to fail.');
        } catch (PayRamApiException) {
            // expected
        }

        $this->assertSame(
            'FAILED',
            DB::table('organizer_payram_accounts')->where('organizer_id', $this->organizerId)->value('status'),
        );

        $payload = $handler->handle($this->organizerId);

        $this->assertSame('READY', $payload['status']);
        $this->assertArrayHasKey('credentials', $payload, 'They never saw the first attempt, so hand them over now.');
        $this->assertSame(
            1,
            DB::table('organizer_payram_accounts')->where('organizer_id', $this->organizerId)->count(),
            'A retry must update the row, not create a second one.',
        );
    }

    public function test_provisioning_resumes_when_the_member_already_exists(): void
    {
        // PayRam answers a duplicate member email with 500 + ALREADY_EXIST. An
        // earlier attempt got that far, so this is a resume, not a failure — and
        // it must not mint another project.
        $projectsCreated = 0;

        Http::fake(function ($request) use (&$projectsCreated) {
            $url = $request->url();

            if (str_ends_with($url, '/api/v1/signin')) {
                return Http::response(['accessToken' => 'operator-jwt-token']);
            }

            if (str_ends_with($url, '/api/v1/external-platform')) {
                $projectsCreated++;

                return Http::response(['id' => 77, 'name' => 'Acme Run']);
            }

            if (str_ends_with($url, '/api/v1/member')) {
                return Http::response(['error' => ['code' => 'ALREADY_EXIST', 'message' => 'Already exist.']], 500);
            }

            if (str_contains($url, '/api-key')) {
                return Http::response(['key' => self::ORGANIZER_KEY]);
            }

            return Http::response(['status' => 'ok']);
        });

        $account = $this->provisioningService()->provision($this->organizerId, 'Acme Run', 'organizer@example.com');

        $this->assertSame('READY', $account->getStatus());
        $this->assertSame(self::ORGANIZER_KEY, $account->getApiKey());
        $this->assertSame(1, $projectsCreated, 'A resume must not create a second project.');
    }

    public function test_the_gateway_project_name_is_unique_to_this_merchant(): void
    {
        // Two organizers can legitimately share a display name ("GN Club"), and
        // PayRam requires unique project names. If we sent the display name, a
        // clash would either fail provisioning or — far worse — adopt the other
        // organizer's project and route this one's money into it.
        $this->fakeSuccessfulProvisioning();

        $this->provisioningService()->provision($this->organizerId, 'GN Club', 'gn@example.com');

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/api/v1/external-platform')) {
                return false;
            }
            $name = $request['name'] ?? '';

            // qualified with the organizer id, so it cannot collide
            return $name === sprintf('GN Club (%d)', $this->organizerId);
        });
    }

    public function test_the_gateway_name_respects_payrams_real_validation_rules(): void
    {
        // Measured against the live API, not assumed: `[`, `]`, `#` and `!` are
        // rejected outright; 1 character is too short and 80 too long. Getting
        // this wrong fails provisioning with "Project name is invalid.", which
        // is exactly what happened when this was first written.
        $cases = [
            ['GN Club', 26],
            ['Test!Org #1 [x]', 8],
            ['a', 5],
            ['', 7],
        ];

        foreach ($cases as [$name, $organizerId]) {
            $gateway = PayRamMerchantProvisioningService::gatewayProjectName($name, $organizerId);

            $this->assertStringNotContainsString('[', $gateway);
            $this->assertStringNotContainsString(']', $gateway);
            $this->assertStringNotContainsString('#', $gateway);
            $this->assertStringNotContainsString('!', $gateway);
            $this->assertGreaterThanOrEqual(2, mb_strlen($gateway), "too short: {$gateway}");
            $this->assertLessThanOrEqual(64, mb_strlen($gateway), "too long: {$gateway}");
            $this->assertStringEndsWith("({$organizerId})", $gateway, "must be unique per merchant: {$gateway}");
        }

        // Two organizers with the same display name must never collide.
        $this->assertNotSame(
            PayRamMerchantProvisioningService::gatewayProjectName('GN Club', 26),
            PayRamMerchantProvisioningService::gatewayProjectName('GN Club', 29),
        );
    }

    public function test_a_taken_name_is_retried_with_a_variant_not_fatal(): void
    {
        // PayRam has no delete endpoint, so a name used once is gone for good —
        // including one squatted by a failed attempt. Failing on that would leave
        // the organizer permanently unable to onboard.
        $names = [];
        Http::fake(function ($request) use (&$names) {
            $url = $request->url();

            if (str_ends_with($url, '/api/v1/signin')) {
                return Http::response(['accessToken' => 'operator-jwt-token']);
            }

            if (str_ends_with($url, '/api/v1/external-platform')) {
                $names[] = $request['name'];

                // First choice is taken; the variant must be accepted.
                if (count($names) === 1) {
                    return Http::response(['error' => ['code' => 'DUPLICATE_PROJECT_NAME']], 409);
                }

                return Http::response(['id' => 77, 'name' => $request['name']]);
            }

            if (str_contains($url, '/api-key')) {
                return Http::response(['key' => self::ORGANIZER_KEY]);
            }

            return Http::response(['id' => 11]);
        });

        $account = $this->provisioningService()->provision($this->organizerId, 'GN Club', 'gn@example.com');

        $this->assertSame('READY', $account->getStatus());
        $this->assertSame(77, $account->getExternalPlatformId());
        $this->assertCount(2, $names, 'It must retry once, with a different name.');
        $this->assertNotSame($names[0], $names[1], 'The retry must send a *different* name.');
        $this->assertStringStartsWith('GN Club ', $names[1]);
    }

    public function test_a_duplicate_name_is_never_adopted(): void
    {
        // Even when the gateway answers with a clash, we must not go looking for
        // a project by that name and claim it — that is how one organizer would
        // end up being paid into another organizer's wallet.
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_ends_with($url, '/api/v1/signin')) {
                return Http::response(['accessToken' => 'operator-jwt-token']);
            }

            if (str_ends_with($url, '/api/v1/external-platform')) {
                return Http::response(['error' => ['code' => 'DUPLICATE_PROJECT_NAME']], 409);
            }

            // If the code ever tries to look projects up by name, hand it a
            // project that is NOT ours and see whether it dares to use it.
            if (str_ends_with($url, '/api/v1/external-platform/all')) {
                return Http::response([['id' => 77, 'name' => 'GN Club [org 999]']]);
            }

            return Http::response(['status' => 'ok']);
        });

        try {
            $this->provisioningService()->provision($this->organizerId, 'GN Club', 'gn@example.com');
            $this->fail('A rejected project creation must not silently resolve to another project.');
        } catch (PayRamApiException) {
            // expected
        }

        // and it must not have adopted anything
        $account = DB::table('organizer_payram_accounts')->where('organizer_id', $this->organizerId)->first();
        $this->assertNull($account?->external_platform_id, 'No project may be adopted from a name clash.');
    }
}
