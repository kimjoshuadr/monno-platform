<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

class CannotPublishEventWithoutPaymentMethodException extends BaseException
{
    public function __construct(
        string $message = 'Cannot publish event: This event has paid tickets, but no active payment method is configured. Please enable Crypto (PayRam), Stripe, or Offline Payments in Event Settings before publishing.',
        int $code = 422,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
