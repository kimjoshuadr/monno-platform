<?php

namespace Tests\Feature\Services\Domain\Payment\PayRam;

use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Http\Actions\Orders\Payment\PayRam\PayRamCancelAction;
use HiEvents\Http\Actions\Orders\Payment\PayRam\PayRamReturnAction;
use HiEvents\Http\Actions\Organizers\PayRam\ExchangePayRamSsoCodeAction;
use HiEvents\Models\Account;
use HiEvents\Models\Order;
use HiEvents\Models\User;
use HiEvents\Services\Application\Handlers\Organizer\Payment\PayRam\CreatePayRamSsoTokenHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayRamCryptoConnectTest extends TestCase
{
    use DatabaseTransactions;

    private int $organizerId;

    private int $eventId;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => 'https://app.monno.io',
            'services.payram.enabled' => true,
            'services.payram.base_url' => 'https://pay.test',
            'services.payram.operator_email' => 'admin@monno.io',
            'services.payram.operator_password' => 'secret',
        ]);

        $account = Account::factory()->create();
        $user = User::factory()->create();

        $this->organizerId = DB::table('organizers')->insertGetId([
            'account_id' => $account->id,
            'name' => 'Crypto Test Organizer',
            'email' => 'crypto@test.com',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->eventId = DB::table('events')->insertGetId([
            'title' => 'Crypto Test Event',
            'short_id' => 'EVTCRYPTO01',
            'organizer_id' => $this->organizerId,
            'account_id' => $account->id,
            'user_id' => $user->id,
            'currency' => 'USD',
            'status' => 'LIVE',
            'start_date' => now()->addDay(),
            'end_date' => now()->addDay()->addHours(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->order = Order::find(DB::table('orders')->insertGetId([
            'event_id' => $this->eventId,
            'short_id' => 'ORD12345',
            'public_id' => 'pub_ord12345',
            'status' => OrderStatus::RESERVED->name,
            'payment_status' => 'AWAITING_PAYMENT',
            'total_gross' => 50.00,
            'currency' => 'USD',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@doe.com',
            'session_id' => 'test-session-123',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_return_action_redirects_to_summary_if_order_completed(): void
    {
        $this->order->update(['status' => OrderStatus::COMPLETED->name]);

        $action = app(PayRamReturnAction::class);
        $request = Request::create('/public/payram/return', 'GET', ['invoice_id' => 'ORD12345']);

        $response = $action($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertStringContainsString('/checkout/'.$this->eventId.'/ORD12345/summary', $response->getTargetUrl());
    }

    public function test_return_action_redirects_to_payment_return_if_pending(): void
    {
        $action = app(PayRamReturnAction::class);
        $request = Request::create('/public/payram/return', 'GET', ['invoice_id' => 'ORD12345']);

        $response = $action($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertStringContainsString('/checkout/'.$this->eventId.'/ORD12345/payment_return', $response->getTargetUrl());
    }

    public function test_cancel_action_redirects_to_payment_with_canceled_flag(): void
    {
        $action = app(PayRamCancelAction::class);
        $request = Request::create('/public/payram/cancel', 'GET', ['invoice_id' => 'ORD12345']);

        $response = $action($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertStringContainsString('/checkout/'.$this->eventId.'/ORD12345/payment?canceled=1', $response->getTargetUrl());
    }

    public function test_sso_exchange_requires_a_code(): void
    {
        $action = app(ExchangePayRamSsoCodeAction::class);

        $response = $action(Request::create('/public/payram/sso-exchange', 'POST', []));

        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_sso_exchange_returns_the_session_once(): void
    {
        Cache::put(
            CreatePayRamSsoTokenHandler::CACHE_PREFIX.'one-time-code',
            ['accessToken' => 'member-token', 'refreshToken' => 'refresh-token', 'resetPasswordRequired' => true],
            60,
        );

        $action = app(ExchangePayRamSsoCodeAction::class);

        $first = $action(Request::create('/public/payram/sso-exchange', 'POST', ['code' => 'one-time-code']));
        $this->assertEquals(200, $first->getStatusCode());

        $session = $first->getData(true)['session'];
        $this->assertSame('member-token', $session['accessToken']);
        $this->assertSame('refresh-token', $session['refreshToken']);
        // The first-login password reset is cleared — Monno already authenticated them.
        $this->assertFalse($session['resetPasswordRequired']);

        // The code is single-use: a replay finds nothing.
        $replay = $action(Request::create('/public/payram/sso-exchange', 'POST', ['code' => 'one-time-code']));
        $this->assertEquals(410, $replay->getStatusCode());
    }
}
