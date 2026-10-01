<?php

namespace Tests\Unit\Services\Domain\Payment\PayRam\DTOs;

use HiEvents\Services\Domain\Payment\PayRam\DTOs\CreatePayRamPaymentResponseDTO;
use Tests\TestCase;

class CreatePayRamPaymentResponseDTOTest extends TestCase
{
    public function test_it_exposes_the_ticket_value_separately_from_the_fee(): void
    {
        $dto = new CreatePayRamPaymentResponseDTO(
            referenceId: 'ref-1',
            checkoutUrl: 'https://pay.monno.io/payments?reference_id=ref-1',
            amountInUsd: 17.96,      // what the buyer is charged
            platformFeeUsd: 0.45,    // the fee line
            orderAmount: 1099.00,
            orderCurrency: 'PHP',
            fxRate: 0.0159326661,
            expiresAt: '2026-10-01T07:00:00+00:00',
        );

        // The checkout shows ₱1,099 ≈ $17.51 + $0.45 processing = $17.96 due,
        // so the ticket's USD value has to be the grossed-up amount minus the fee.
        $this->assertSame(17.51, $dto->ticketAmountInUsd());
        $this->assertSame('17.51', $dto->ticketAmountInUsdFormatted());
        $this->assertSame('17.96', $dto->amountInUsdFormatted());
        $this->assertSame('0.45', $dto->platformFeeUsdFormatted());

        $this->assertEqualsWithDelta(
            $dto->ticketAmountInUsd() + $dto->platformFeeUsd,
            $dto->amountInUsd,
            0.005,
            'Ticket value plus fee must equal the amount charged.',
        );
    }

    public function test_it_handles_a_payment_with_no_fee(): void
    {
        $dto = new CreatePayRamPaymentResponseDTO(
            referenceId: 'ref-2',
            checkoutUrl: 'https://pay.monno.io/payments?reference_id=ref-2',
            amountInUsd: 25.0,
            platformFeeUsd: 0.0,
            orderAmount: 25.0,
            orderCurrency: 'USD',
            fxRate: 1.0,
            expiresAt: '2026-10-01T07:00:00+00:00',
        );

        $this->assertSame(25.0, $dto->ticketAmountInUsd());
        $this->assertSame('25.00', $dto->ticketAmountInUsdFormatted());
    }
}
