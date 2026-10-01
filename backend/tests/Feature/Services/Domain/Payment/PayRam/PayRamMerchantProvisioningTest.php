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
            && $request['name'] === 'Acme Run');
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

        // One provisioning run (signin, project, member, roles, api-key) plus one
        // gateway status read when the account is presented. The second present()
        // is served from the status cache, so it adds no call.
        Http::assertSentCount(6);
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
}
