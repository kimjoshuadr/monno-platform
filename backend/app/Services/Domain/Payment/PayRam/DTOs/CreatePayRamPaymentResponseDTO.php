<?php

namespace HiEvents\Services\Domain\Payment\PayRam\DTOs;

readonly class CreatePayRamPaymentResponseDTO
{
    public function __construct(
        public string $referenceId,
        public string $checkoutUrl,
        public float $amountInUsd,
        public float $platformFeeUsd,
        public float $orderAmount,
        public string $orderCurrency,
        public float $fxRate,
        public string $expiresAt,
    ) {}

    /**
     * "19.30" — shown next to the local-currency total at checkout.
     */
    public function amountInUsdFormatted(): string
    {
        return number_format($this->amountInUsd, 2, '.', '');
    }

    /**
     * What the ticket itself is worth in USD — the grossed-up amount minus the
     * fee, so the checkout line reads ₱1,099 ≈ $17.51 + $0.45 = $17.96.
     */
    public function ticketAmountInUsd(): float
    {
        return round($this->amountInUsd - $this->platformFeeUsd, 2);
    }

    public function ticketAmountInUsdFormatted(): string
    {
        return number_format($this->ticketAmountInUsd(), 2, '.', '');
    }

    public function platformFeeUsdFormatted(): string
    {
        return number_format($this->platformFeeUsd, 2, '.', '');
    }
}
