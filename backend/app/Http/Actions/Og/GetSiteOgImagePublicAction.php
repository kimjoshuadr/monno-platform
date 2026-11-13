<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Og;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Infrastructure\OgImage\OgImageService;
use Illuminate\Http\Response;

/**
 * `GET /public/og/site` — the generic monno card, for pages with nothing more
 * specific to show (home, calendars index, legal).
 */
class GetSiteOgImagePublicAction extends BaseAction
{
    use RendersOgImages;

    public function __construct(
        private readonly OgImageService $og,
    ) {}

    public function __invoke(): Response
    {
        return $this->ogPng($this->og->render(
            'Events worth showing up for',
            'monno',
            null,
            false,
            false,
        ));
    }
}
