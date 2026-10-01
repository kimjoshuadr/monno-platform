<?php

namespace Tests\Unit\Services\Domain\Payment\PayRam;

use HiEvents\Services\Domain\Payment\PayRam\PayRamStatusPayloadMapper;
use Tests\TestCase;

class PayRamStatusPayloadMapperTest extends TestCase
{
    private PayRamStatusPayloadMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new PayRamStatusPayloadMapper;
    }

    public function test_it_maps_a_filled_status_response_into_the_webhook_shape(): void
    {
        $payload = $this->mapper->toWebhookPayload([
            'referenceID' => 'ref-123',
            'invoiceID' => 'o_ABC123',
            'customerID' => 'o_ABC123',
            'paymentState' => 'FILLED',
            'amountInUSD' => '17.96',
            'filledAmount' => '17.96',
            'filledAmountInUSD' => '17.96',
            'confirmationCurrent' => 32,
            'confirmationRequired' => 32,
            'blockchainSymbol' => 'USDC',
            'depositAddress' => '0xPayRamDeposit',
            'explorerTransaction' => '0xdeadbeef',
        ]);

        $this->assertSame('ref-123', $payload['reference_id']);
        $this->assertSame('o_ABC123', $payload['invoice_id']);
        $this->assertSame('FILLED', $payload['status']);
        $this->assertSame('17.96', $payload['filled_amount_in_usd']);
        $this->assertSame(32, $payload['confirmation_current']);
        $this->assertSame('USDC', $payload['currency']);
        $this->assertTrue($payload['polled']);

        // the destination address matters for reconciliation
        $this->assertSame('0xPayRamDeposit', $payload['payment_info'][0]['destination_address']);
        $this->assertSame('0xdeadbeef', $payload['payment_info'][0]['transaction_hash']);
    }

    public function test_it_maps_an_open_status_without_fabricating_deposit_details(): void
    {
        $payload = $this->mapper->toWebhookPayload([
            'referenceID' => 'ref-456',
            'paymentState' => 'OPEN',
            'amountInUSD' => '25.65',
            'filledAmount' => null,
            'filledAmountInUSD' => null,
            'depositAddress' => null,
            'explorerTransaction' => '',
        ]);

        $this->assertSame('OPEN', $payload['status']);
        $this->assertArrayNotHasKey('payment_info', $payload, 'No deposit seen, so no deposit details.');
        $this->assertArrayNotHasKey('filled_amount_in_usd', $payload, 'Nulls are dropped rather than stored as zero.');
    }

    public function test_it_rejects_a_response_without_a_reference_or_state(): void
    {
        $this->assertNull($this->mapper->toWebhookPayload([]));
        $this->assertNull($this->mapper->toWebhookPayload(['referenceID' => 'ref-1']));
        $this->assertNull($this->mapper->toWebhookPayload(['paymentState' => 'FILLED']));
    }
}
