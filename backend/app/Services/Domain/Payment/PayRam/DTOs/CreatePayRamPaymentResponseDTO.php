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

    public function platformFeeUsdFormatted(): string
    {
        return number_format($this->platformFeeUsd, 2, '.', '');
    }
}
