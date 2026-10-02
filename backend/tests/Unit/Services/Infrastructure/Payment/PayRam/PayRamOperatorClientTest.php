<?php

namespace Tests\Unit\Services\Infrastructure\Payment\PayRam;

use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamConfigurationService;
use HiEvents\Services\Infrastructure\Payment\PayRam\PayRamOperatorClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayRamOperatorClientTest extends TestCase
{
    private PayRamOperatorClient $client;

    protected function setUp(): void
    {
        parent::setUp();

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
}
