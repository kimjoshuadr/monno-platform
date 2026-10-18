<?php

namespace HiEvents\DomainObjects\Enums;

/**
 * PLATFORM — tickets are sold on the platform (default).
 * EXTERNAL — the organizer sells tickets elsewhere; the platform redirects buyers.
 */
enum TicketingMode
{
    use BaseEnum;

    case PLATFORM;
    case EXTERNAL;
}
