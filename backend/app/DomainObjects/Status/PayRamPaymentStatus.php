<?php

namespace HiEvents\DomainObjects\Status;

/**
 * PayRam fill states, exactly as the gateway reports them.
 */
enum PayRamPaymentStatus: string
{
    case OPEN = 'OPEN';
    case PARTIALLY_FILLED = 'PARTIALLY_FILLED';
    case FILLED = 'FILLED';
    case OVER_FILLED = 'OVER_FILLED';
    case CANCELLED = 'CANCELLED';

    /**
     * Money is considered received.
     */
    public function isSettled(): bool
    {
        return $this === self::FILLED || $this === self::OVER_FILLED;
    }

    public function isTerminal(): bool
    {
        return $this->isSettled() || $this === self::CANCELLED;
    }
}
