<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\OgImage;

use GdImage;
use Throwable;

/**
 * Renders the branded Open Graph card: a 1200×630 PNG with the monno ink
 * background, the spectrum rule and wordmark, the page's title, and a 1:1 tile
 * of the entity's image (the event's square cover, or the organizer's logo).
 *
 * GD only — the all-in-one image has GD but not Imagick. Everything degrades:
 * a missing image leaves the tile blank rather than failing the response.
 */
class OgImageService
{
    private const WIDTH = 1200;
    private const HEIGHT = 630;
    private const PAD = 72;
    private const TILE = 372;

    /** The brand spectrum, left to right. */
    private const SPECTRUM = ['#0FCFF6', '#2BE09D', '#B2D64A', '#F6D52C', '#FEB52A', '#FD7E2B', '#FD5A3C'];

    private string $fontBold;
    private string $fontRegular;

    public function __construct()
    {
        $this->fontBold = resource_path('fonts/Outfit-Bold.ttf');
        $this->fontRegular = resource_path('fonts/Outfit-Regular.ttf');
    }

    public function render(string $title, ?string $kicker = null, ?string $imageBytes = null): string
    {
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefilledrectangle($canvas, 0, 0, self::WIDTH, self::HEIGHT, $this->color($canvas, '#0B0B0C'));

        $this->drawSpectrumRule($canvas, self::PAD, 104, 7, 26, 6);
        $this->drawText($canvas, 'monno', self::PAD, 190, 54, '#FFFFFF', true);

        if ($kicker !== null && $kicker !== '') {
            $this->drawText($canvas, $this->truncate($kicker, 52), self::PAD, 268, 26, '#8A8E96');
        }

        $lines = $this->wrap($title, 46, 640, 4);
        $y = 348;
        foreach ($lines as $line) {
            $this->drawText($canvas, $line, self::PAD, $y, 46, '#FFFFFF', true);
            $y += 62;
        }

        $this->drawTile($canvas, self::WIDTH - self::PAD - self::TILE, (int) ((self::HEIGHT - self::TILE) / 2), $imageBytes);

        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();
        imagedestroy($canvas);

        return $png;
    }

    private function drawTile(GdImage $canvas, int $x, int $y, ?string $imageBytes): void
    {
        $size = self::TILE;
        imagefilledrectangle($canvas, $x, $y, $x + $size, $y + $size, $this->color($canvas, '#16161A'));

        if ($imageBytes !== null) {
            try {
                $source = @imagecreatefromstring($imageBytes);
            } catch (Throwable) {
                $source = false;
            }

            if ($source instanceof GdImage) {
                $sw = imagesx($source);
                $sh = imagesy($source);
                $side = min($sw, $sh);
                $sx = (int) (($sw - $side) / 2);
                $sy = (int) (($sh - $side) / 2);

                imagecopyresampled($canvas, $source, $x, $y, $sx, $sy, $size, $size, $side, $side);
                imagedestroy($source);
            }
        }

        // A hairline edge so the tile reads on the dark ground.
        imagerectangle($canvas, $x, $y, $x + $size, $y + $size, $this->color($canvas, '#2A2A30'));
    }

    private function drawSpectrumRule(GdImage $canvas, int $x, int $y, int $segments, int $segW, int $segH): void
    {
        foreach (self::SPECTRUM as $i => $hex) {
            if ($i >= $segments) {
                break;
            }
            $left = $x + ($i * $segW);
            imagefilledrectangle($canvas, $left, $y, $left + $segW, $y + $segH, $this->color($canvas, $hex));
        }
    }

    private function drawText(GdImage $canvas, string $text, int $x, int $baseline, int $size, string $hex, bool $bold = false): void
    {
        $font = $bold ? $this->fontBold : $this->fontRegular;

        if (! is_file($font)) {
            imagestring($canvas, 5, $x, $baseline, $text, $this->color($canvas, $hex));

            return;
        }

        imagettftext($canvas, $size, 0, $x, $baseline, $this->color($canvas, $hex), $font, $text);
    }

    /**
     * @return array<int, string>
     */
    private function wrap(string $text, int $size, int $maxWidth, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;

            if ($this->textWidth($candidate, $size) <= $maxWidth || $line === '') {
                $line = $candidate;
                continue;
            }

            $lines[] = $line;
            $line = $word;

            if (count($lines) === $maxLines) {
                break;
            }
        }

        if ($line !== '' && count($lines) < $maxLines) {
            $lines[] = $line;
        }

        if (count($lines) === $maxLines) {
            $lines[$maxLines - 1] = $this->truncate($lines[$maxLines - 1].' …', 40);
        }

        return $lines === [] ? [''] : $lines;
    }

    private function textWidth(string $text, int $size): int
    {
        if (! is_file($this->fontBold)) {
            return imagefontwidth(5) * strlen($text);
        }

        $box = imagettfbbox($size, 0, $this->fontBold, $text);

        return $box ? abs($box[2] - $box[0]) : 0;
    }

    private function truncate(string $text, int $limit): string
    {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }

    private function color(GdImage $canvas, string $hex): int
    {
        $hex = ltrim($hex, '#');
        [$r, $g, $b] = sscanf($hex, '%02x%02x%02x');

        return (int) imagecolorallocate($canvas, (int) $r, (int) $g, (int) $b);
    }
}
