<?php

namespace HiEvents\Services\Domain\Payment\PayRam\DTOs;

readonly class CreatePayRamPaymentResponseDTO
{
    public function __construct(
        public string $referenceId,
        public string $checkoutUrl,
        public float $amountInUsd,
        public float $payramFeeUsd,
        public int $feeRateBps,
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
     * What the ticket itself is worth in USD — the grossed-up amount minus
     * PayRam's fee, so the checkout line reads ₱1,099 ≈ $17.51 + $0.44 = $17.95.
     */
    public function ticketAmountInUsd(): float
    {
        return round($this->amountInUsd - $this->payramFeeUsd, 2);
    }

    public function ticketAmountInUsdFormatted(): string
    {
        return number_format($this->ticketAmountInUsd(), 2, '.', '');
    }

    public function payramFeeUsdFormatted(): string
    {
        return number_format($this->payramFeeUsd, 2, '.', '');
    }

    /**
     * "2.5" — the rate shown beside the fee line, without forcing a trailing
     * zero or a misleadingly precise figure.
     */
    public function feeRatePercent(): string
    {
        return rtrim(rtrim(number_format($this->feeRateBps / 100, 2, '.', ''), '0'), '.') ?: '0';
    }
}
