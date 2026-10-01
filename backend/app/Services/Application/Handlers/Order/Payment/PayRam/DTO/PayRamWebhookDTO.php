<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\PayRam\DTO;

readonly class PayRamWebhookDTO
{
    public function __construct(
        public string $rawBody,
        public ?string $signature,
    ) {}
}
