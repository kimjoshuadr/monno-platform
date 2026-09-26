<?php

namespace Tests\Unit\DomainObjects\Enums;

use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use Tests\TestCase;

class ImageTypeTest extends TestCase
{
    public function test_event_image_requires_a_square_minimum(): void
    {
        $this->assertSame([600, 600], ImageType::getMinimumDimensionsMap(ImageType::EVENT_IMAGE));
    }

    public function test_event_cover_keeps_its_wide_minimum(): void
    {
        $this->assertSame([600, 50], ImageType::getMinimumDimensionsMap(ImageType::EVENT_COVER));
    }

    public function test_event_image_is_an_event_image_type(): void
    {
        $this->assertContains(ImageType::EVENT_IMAGE, ImageType::eventImageTypes());
        $this->assertSame(EventDomainObject::class, ImageType::EVENT_IMAGE->getEntityType());
    }
}
