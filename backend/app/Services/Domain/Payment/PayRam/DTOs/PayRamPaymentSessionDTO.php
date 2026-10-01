<?php

namespace HiEvents\Services\Domain\Payment\PayRam\DTOs;

readonly class PayRamPaymentSessionDTO
{
    public function __construct(
        public string $referenceId,
        public string $checkoutUrl,
        public string $host,
    ) {}
}
