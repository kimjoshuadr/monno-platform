<?php

namespace Tests\Feature\Services\Domain\Payment\PayRam;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Exceptions\CannotAcceptPaymentException;
use HiEvents\Models\Account;
use HiEvents\Models\User;
use HiEvents\Services\Application\Handlers\Order\Payment\PayRam\DTO\PayRamWebhookDTO;
use HiEvents\Services\Domain\Payment\PayRam\PayRamIncomingWebhookHandler;
use HiEvents\Services\Domain\Payment\PayRam\PayRamPaymentSettlementHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PayRamSettlementTest extends TestCase
{
    use DatabaseTransactions;

    private const REFERENCE = 'payram-ref-settlement-1';

    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $account = Account::factory()->create();

        $this->eventId = DB::table('events')->insertGetId([
            'title' => 'PayRam settlement test event',
            'short_id' => 'EVTPAYRAMTEST',
            'account_id' => $account->id,
            'user_id' => $user->id,
            'currency' => 'USD',
            'status' => 'LIVE',
            'start_date' => now()->addDay(),
            'end_date' => now()->addDay()->addHours(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{id: int, short_id: string}
     */
    private function makeReservedOrder(array $overrides = []): array
    {
        $id = DB::table('orders')->insertGetId(array_merge([
            'event_id' => $this->eventId,
            'short_id' => 'ORD' . strtoupper(substr(uniqid(), -8)),
            'public_id' => (string) random_int(100000, 999999),
            'currency' => 'USD',
            'status' => 'RESERVED',
            'payment_status' => 'AWAITING_PAYMENT',
            'payment_provider' => null,
            'total_gross' => 25.00,
            'total_before_additions' => 25.00,
            'total_refunded' => 0,
            'reserved_until' => now()->addMinutes(30),
            'email' => 'buyer@example.com',
            'session_id' => 'session-test-1',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return ['id' => $id, 'short_id' => (string) DB::table('orders')->where('id', $id)->value('short_id')];
    }

    private function makePayment(array $order): void
    {
        DB::table('payram_payments')->insert([
            'order_id' => $order['id'],
            'reference_id' => self::REFERENCE,
            'invoice_id' => $order['short_id'],
            'customer_id' => $order['short_id'],
            'checkout_url' => 'https://pay.monno.io/payments?reference_id=' . self::REFERENCE,
            'amount_in_usd' => 19.80,
            'order_currency' => 'USD',
            'order_amount' => 19.30,
            'fx_rate' => 1.0,
            'platform_fee_usd' => 0.65,
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function orderRow(array $order): object
    {
        return DB::table('orders')->where('id', $order['id'])->first();
    }

    private function paymentRow(): object
    {
        return DB::table('payram_payments')->where('reference_id', self::REFERENCE)->first();
    }

    private function filledPayload(array $order, array $overrides = []): array
    {
        return array_merge([
            'reference_id' => self::REFERENCE,
            'invoice_id' => $order['short_id'],
            'status' => 'FILLED',
            'amount' => '19.80',
            'currency' => 'USDC',
            'filled_amount' => '19.80',
            'filled_amount_in_usd' => '19.80',
            'confirmation_current' => 12,
            'confirmation_required' => 12,
            'timestamp' => now()->timestamp,
            'payment_info' => [[
                'source_address' => '0x1111111111111111111111111111111111111111',
                'transaction_hash' => '0xdeadbeef',
                'destination_address' => '0x2222222222222222222222222222222222222222',
                'block_number' => 123456,
            ]],
        ], $overrides);
    }

    public function test_a_filled_payment_marks_the_order_paid_and_records_the_deposit(): void
    {
        Bus::fake();

        $order = $this->makeReservedOrder();
        $this->makePayment($order);

        app(PayRamPaymentSettlementHandler::class)->handle($this->filledPayload($order));

        $row = $this->orderRow($order);
        $this->assertSame('COMPLETED', $row->status);
        $this->assertSame('PAYMENT_RECEIVED', $row->payment_status);
        $this->assertSame(PaymentProviders::PAYRAM->value, $row->payment_provider);

        $payment = $this->paymentRow();
        $this->assertSame('FILLED', $payment->status);
        $this->assertSame(19.80, (float) $payment->filled_amount_in_usd);
        $this->assertSame(
            '0x1111111111111111111111111111111111111111',
            $payment->source_address,
            'The buyer sending address must be stored — it is the refund destination.',
        );
        $this->assertSame('0xdeadbeef', $payment->transaction_hash);
        $this->assertNotNull($payment->paid_at);

        // The fee line charged on top of the ticket is recorded like Stripe's.
        $this->assertSame(
            1,
            DB::table('order_application_fees')->where('order_id', $order['id'])->count(),
        );
    }

    public function test_settlement_is_idempotent_when_payram_redelivers(): void
    {
        Bus::fake();

        $order = $this->makeReservedOrder();
        $this->makePayment($order);

        $handler = app(PayRamPaymentSettlementHandler::class);
        $handler->handle($this->filledPayload($order));
        $handler->handle($this->filledPayload($order));

        $this->assertSame('COMPLETED', $this->orderRow($order)->status);
        $this->assertSame(
            1,
            DB::table('order_application_fees')->where('order_id', $order['id'])->count(),
            'A redelivered webhook must not double-record the platform fee.',
        );
        $this->assertSame(
            1,
            DB::table('payram_payments')->where('reference_id', self::REFERENCE)->count(),
        );
    }

    public function test_it_refuses_to_settle_a_cancelled_order(): void
    {
        Bus::fake();

        $order = $this->makeReservedOrder(['status' => 'CANCELLED']);
        $this->makePayment($order);

        try {
            app(PayRamPaymentSettlementHandler::class)->handle($this->filledPayload($order));
            $this->fail('Expected the settlement to be refused.');
        } catch (CannotAcceptPaymentException) {
            // expected — the money must not silently complete a dead order
        }

        $row = $this->orderRow($order);
        $this->assertSame('CANCELLED', $row->status);
        $this->assertNotSame('PAYMENT_RECEIVED', $row->payment_status);
        $this->assertSame('OPEN', $this->paymentRow()->status);
    }

    public function test_partially_filled_payments_are_recorded_but_do_not_settle_the_order(): void
    {
        Bus::fake();

        $order = $this->makeReservedOrder();
        $this->makePayment($order);

        $payload = $this->filledPayload($order, [
            'status' => 'PARTIALLY_FILLED',
            'filled_amount' => '5.00',
            'filled_amount_in_usd' => '5.00',
            'confirmation_current' => 1,
        ]);

        app(PayRamIncomingWebhookHandler::class)->handle(
            new PayRamWebhookDTO(rawBody: json_encode($payload), signature: 'sha256=test'),
        );

        $this->assertSame('RESERVED', $this->orderRow($order)->status);
        $this->assertNotSame('PAYMENT_RECEIVED', $this->orderRow($order)->payment_status);

        $payment = $this->paymentRow();
        $this->assertSame('PARTIALLY_FILLED', $payment->status);
        $this->assertSame(5.00, (float) $payment->filled_amount_in_usd);
    }

    public function test_payout_events_are_ignored_rather_than_treated_as_payments(): void
    {
        Bus::fake();

        $order = $this->makeReservedOrder();
        $this->makePayment($order);

        $payload = [
            'event_type' => 'payout.sent',
            'payout_id' => 42,
            'status' => 'SENT',
            'amount' => '19.80',
            'amount_usd' => '19.80',
        ];

        app(PayRamIncomingWebhookHandler::class)->handle(
            new PayRamWebhookDTO(rawBody: json_encode($payload), signature: 'sha256=test'),
        );

        $this->assertSame('RESERVED', $this->orderRow($order)->status);
        $this->assertSame('OPEN', $this->paymentRow()->status);
    }
}
