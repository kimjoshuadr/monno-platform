<?php

namespace HiEvents\DomainObjects\Enums;

enum ImageProcessingState
{
    use BaseEnum;

    case PENDING;
    case READY;
    case FAILED;
}
