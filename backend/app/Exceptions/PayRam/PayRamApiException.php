<?php

namespace HiEvents\Exceptions\PayRam;

use Exception;

class PayRamApiException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $rawBody = null,
    ) {
        parent::__construct($message);
    }
}
