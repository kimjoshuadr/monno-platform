<?php

namespace Tests\Unit\Services\Infrastructure\Payment\PayRam;

use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamConfigurationService;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayRamOperatorClientTest extends TestCase
{
    private PayRamOperatorClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.payram.enabled' => true,
            'services.payram.base_url' => 'https://pay.test',
            'services.payram.operator_email' => 'admin@monno.io',
            'services.payram.operator_password' => 'operator-pass',
        ]);

        $this->client = new PayRamOperatorClient(new PayRamConfigurationService);
    }

    public function test_it_signs_in_member_directly_when_no_password_reset_required(): void
    {
        Http::fake([
            'https://pay.test/api/v1/signin' => Http::response([
                'accessToken' => 'access-token-123',
                'refreshToken' => 'refresh-token-123',
                'resetPasswordRequired' => false,
            ]),
        ]);

        $session = $this->client->signInMember('user@example.com', 'secret-pass');

        $this->assertSame('access-token-123', $session['accessToken']);
        $this->assertFalse($session['resetPasswordRequired']);
        Http::assertSentCount(1);
    }

    public function test_it_proactively_clears_reset_password_required(): void
    {
        Http::fake([
            'https://pay.test/api/v1/signin' => Http::sequence()
                ->push([
                    'accessToken' => 'initial-token',
                    'refreshToken' => 'initial-refresh',
                    'resetPasswordRequired' => true,
                ])
                ->push([
                    'accessToken' => 'final-token',
                    'refreshToken' => 'final-refresh',
                    'resetPasswordRequired' => false,
                ]),
            'https://pay.test/api/v1/member/change-password' => Http::response([
                'message' => 'Password changed successfully',
            ]),
        ]);

        $session = $this->client->signInMember('user@example.com', 'secret-pass');

        $this->assertSame('final-token', $session['accessToken']);
        $this->assertFalse($session['resetPasswordRequired']);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/api/v1/member/change-password')
                && $request->hasHeader('Authorization', 'Bearer initial-token')
                && $request['oldPassword'] === 'secret-pass'
                && $request['password'] === 'secret-pass';
        });
    }

    public function test_it_assigns_the_shared_hot_wallet_by_appending_to_existing_projects(): void
    {
        Http::fake([
            'https://pay.test/api/v1/signin' => Http::response(['accessToken' => 'operator-token']),
            'https://pay.test/api/v1/project/all/wallets/5/assignable-projects' => Http::response([
                'projects' => [
                    ['projectID' => 2, 'status' => 'currently_assigned'],
                    ['projectID' => 9, 'status' => 'compatible'],
                ],
            ]),
            'https://pay.test/api/v1/wallets/5/projects' => Http::response(['success' => true]),
        ]);

        $this->client->assignHotWallet(9, 5);

        // The assignment endpoint takes the FULL list, so the existing project
        // must be preserved and the new one appended.
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/api/v1/wallets/5/projects')
            && $request['projectIds'] === [2, 9]);
    }

    public function test_it_is_idempotent_when_the_project_is_already_assigned(): void
    {
        Http::fake([
            'https://pay.test/api/v1/signin' => Http::response(['accessToken' => 'operator-token']),
            'https://pay.test/api/v1/project/all/wallets/5/assignable-projects' => Http::response([
                'projects' => [
                    ['projectID' => 9, 'status' => 'currently_assigned'],
                ],
            ]),
        ]);

        $this->client->assignHotWallet(9, 5);

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/v1/wallets/5/projects'));
    }

    public function test_it_does_nothing_without_a_hot_wallet_id(): void
    {
        Http::fake();

        $this->client->assignHotWallet(9, 0);

        Http::assertNothingSent();
    }
}
