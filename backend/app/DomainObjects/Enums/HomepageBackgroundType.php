<?php

namespace HiEvents\DomainObjects\Enums;

enum HomepageBackgroundType: string
{
    use BaseEnum;

    case MIRROR_COVER_IMAGE = 'MIRROR_COVER_IMAGE';
    case COLOR = 'COLOR';
    case IMAGE = 'IMAGE';
    case VIDEO = 'VIDEO';

    /** Where a media background sits: the whole page, or just the hero band. */
    public static function placements(): array
    {
        return ['PAGE', 'HERO'];
    }
}
