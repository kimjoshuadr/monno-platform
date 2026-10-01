<?php

namespace Tests\Feature\Console;

use HiEvents\Models\Account;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Reconciliation is the settlement trigger while our gateway build has no
 * webhook endpoint registration, so this proves a polled FILLED status marks
 * the order paid end to end.
 */
class PayRamReconcileTest extends TestCase
{
    use DatabaseTransactions;

    private const REFERENCE = 'payram-reconcile-ref-1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payram.enabled' => true,
            'services.payram.base_url' => 'https://pay.test',
            'services.payram.api_key' => 'test-key',
            'services.payram.webhook_secret' => 'test-key',
            'mail.from.address' => 'no-reply@monno.io',
        ]);

        Bus::fake();

        $user = User::factory()->create();
        $account = Account::factory()->create();

        $eventId = DB::table('events')->insertGetId([
            'title' => 'Reconcile test event',
            'short_id' => 'EVTPAYRAMRECON',
            'account_id' => $account->id,
            'user_id' => $user->id,
            'currency' => 'USD',
            'status' => 'LIVE',
            'start_date' => now()->addDay(),
            'end_date' => now()->addDay()->addHours(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'event_id' => $eventId,
            'short_id' => 'oRECON0001',
            'public_id' => (string) random_int(100000, 999999),
            'currency' => 'USD',
            'status' => 'RESERVED',
            'payment_status' => 'AWAITING_PAYMENT',
            'total_gross' => 17.96,
            'total_before_additions' => 17.96,
            'total_refunded' => 0,
            'reserved_until' => now()->addMinutes(30),
            'email' => 'buyer@example.com',
            'session_id' => 'recon-session',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payram_payments')->insert([
            'order_id' => $orderId,
            'reference_id' => self::REFERENCE,
            'invoice_id' => 'oRECON0001',
            'customer_id' => 'oRECON0001',
            'checkout_url' => 'https://pay.test/payments?reference_id=' . self::REFERENCE,
            'amount_in_usd' => 17.96,
            'order_currency' => 'USD',
            'order_amount' => 17.51,
            'fx_rate' => 1.0,
            'platform_fee_usd' => 0.45,
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_polled_filled_payment_settles_the_order(): void
    {
        Http::fake(['*' => Http::response([
            'referenceID' => self::REFERENCE,
            'invoiceID' => 'oRECON0001',
            'customerID' => 'oRECON0001',
            'paymentState' => 'FILLED',
            'amountInUSD' => '17.96',
            'filledAmount' => '17.96',
            'filledAmountInUSD' => '17.96',
            'confirmationCurrent' => 32,
            'confirmationRequired' => 32,
            'blockchainSymbol' => 'USDC',
            'depositAddress' => '0xPayRamDepositAddr',
            'explorerTransaction' => '0xpolledhash',
        ])]);

        $exit = Artisan::call('monno:payram-reconcile');

        $this->assertSame(0, $exit);

        $order = DB::table('orders')->where('short_id', 'oRECON0001')->first();
        $this->assertSame('COMPLETED', $order->status);
        $this->assertSame('PAYMENT_RECEIVED', $order->payment_status);
        $this->assertSame('PAYRAM', $order->payment_provider);

        $payment = DB::table('payram_payments')->where('reference_id', self::REFERENCE)->first();
        $this->assertSame('FILLED', $payment->status);
        $this->assertSame('0xpolledhash', $payment->transaction_hash);
        $this->assertSame('0xPayRamDepositAddr', $payment->destination_address);
        $this->assertNotNull($payment->paid_at);

        $this->assertStringContainsString('Settled ' . self::REFERENCE, Artisan::output());
    }

    public function test_an_open_payment_is_left_awaiting_and_not_settled(): void
    {
        Http::fake(['*' => Http::response([
            'referenceID' => self::REFERENCE,
            'invoiceID' => 'oRECON0001',
            'paymentState' => 'OPEN',
            'amountInUSD' => '17.96',
            'filledAmount' => null,
            'filledAmountInUSD' => null,
            'depositAddress' => null,
        ])]);

        Artisan::call('monno:payram-reconcile');

        $order = DB::table('orders')->where('short_id', 'oRECON0001')->first();
        $this->assertSame('RESERVED', $order->status);
        $this->assertNotSame('PAYMENT_RECEIVED', $order->payment_status);
        $this->assertSame('OPEN', DB::table('payram_payments')->where('reference_id', self::REFERENCE)->value('status'));
    }

    public function test_a_cancelled_payment_stops_being_polled(): void
    {
        Http::fake(['*' => Http::response([
            'referenceID' => self::REFERENCE,
            'invoiceID' => 'oRECON0001',
            'paymentState' => 'CANCELLED',
            'amountInUSD' => '17.96',
        ])]);

        Artisan::call('monno:payram-reconcile');

        // terminal, so it must drop out of the next run's open set
        $status = DB::table('payram_payments')->where('reference_id', self::REFERENCE)->value('status');
        $this->assertSame('CANCELLED', $status);

        $order = DB::table('orders')->where('short_id', 'oRECON0001')->first();
        $this->assertSame('RESERVED', $order->status, 'A cancelled link must not complete the order.');

        // terminal payments must leave the poller's working set
        $stillOpen = app(\HiEvents\Repository\Interfaces\PayRamPaymentsRepositoryInterface::class)
            ->findWhereIn('status', ['OPEN', 'PARTIALLY_FILLED']);
        $this->assertFalse(
            $stillOpen->contains(fn ($p) => $p->getReferenceId() === self::REFERENCE),
            'A cancelled payment must not be polled again.',
        );
    }
}
