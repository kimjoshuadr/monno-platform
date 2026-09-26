<?php

namespace HiEvents\DomainObjects\Enums;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use InvalidArgumentException;

enum ImageType
{
    use BaseEnum;

    case GENERIC;

    // Event images
    case EVENT_COVER;
    case EVENT_IMAGE;
    case TICKET_LOGO;
    case EVENT_BACKGROUND;
    case EVENT_BACKGROUND_POSTER;

    // Organizer images
    case ORGANIZER_LOGO;
    case ORGANIZER_COVER;
    case ORGANIZER_BACKGROUND;
    case ORGANIZER_BACKGROUND_POSTER;

    public static function eventImageTypes(): array
    {
        return [
            self::EVENT_COVER,
            self::EVENT_IMAGE,
            self::TICKET_LOGO,
            self::EVENT_BACKGROUND,
            self::EVENT_BACKGROUND_POSTER,
        ];
    }

    public static function organizerImageTypes(): array
    {
        return [
            self::ORGANIZER_LOGO,
            self::ORGANIZER_COVER,
            self::ORGANIZER_BACKGROUND,
            self::ORGANIZER_BACKGROUND_POSTER,
        ];
    }

    public static function genericImageTypes(): array
    {
        return [
            self::GENERIC,
        ];
    }

    public static function getMinimumDimensionsMap(ImageType $imageType): array
    {
        $map = [
            self::GENERIC->name => [50, 50],
            self::EVENT_COVER->name => [600, 50],
            self::EVENT_IMAGE->name => [600, 600],
            self::TICKET_LOGO->name => [100, 100],
            self::ORGANIZER_LOGO->name => [100, 100],
            self::ORGANIZER_COVER->name => [600, 50],
            // Backgrounds are cover-sized artwork; the poster is a video frame, so it only
            // needs to be a real image.
            self::EVENT_BACKGROUND->name => [1280, 720],
            self::ORGANIZER_BACKGROUND->name => [1280, 720],
            self::EVENT_BACKGROUND_POSTER->name => [320, 180],
            self::ORGANIZER_BACKGROUND_POSTER->name => [320, 180],
        ];

        return $map[$imageType->name] ?? $map[self::GENERIC->name];
    }

    public function getEntityType(): string
    {
        if (in_array($this, self::eventImageTypes())) {
            return EventDomainObject::class;
        }

        if (in_array($this, self::organizerImageTypes())) {
            return OrganizerDomainObject::class;
        }

        if (in_array($this, self::genericImageTypes())) {
            return UserDomainObject::class;
        }

        throw new InvalidArgumentException('Invalid image type: '.$this->name);
    }
}
