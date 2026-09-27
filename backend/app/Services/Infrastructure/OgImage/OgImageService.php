<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\OgImage;

use GdImage;
use Throwable;

/**
 * Renders the branded Open Graph card: a 1200×630 PNG with the monno mascot, the
 * spectrum accent and wordmark, the page's title, and a rounded 1:1 tile of the
 * entity's image — over a blurred wash of that same image rather than a flat
 * black ground.
 *
 * GD only — the all-in-one image has GD but not Imagick. Everything degrades:
 * a missing image leaves the ground plain and the tile empty rather than failing
 * the response.
 */
class OgImageService
{
    private const WIDTH = 1200;
    private const HEIGHT = 630;
    private const PAD = 72;
    private const TILE = 372;
    private const TILE_RADIUS = 32;

    /** The brand spectrum, left to right. */
    private const SPECTRUM = ['#0FCFF6', '#2BE09D', '#B2D64A', '#F6D52C', '#FEB52A', '#FD7E2B', '#FD5A3C'];

    private string $fontBold;
    private string $fontRegular;
    private string $markPath;

    public function __construct()
    {
        $this->fontBold = resource_path('fonts/Outfit-Bold.ttf');
        $this->fontRegular = resource_path('fonts/Outfit-Regular.ttf');
        $this->markPath = resource_path('og/monno-mark.png');
    }

    public function render(string $title, ?string $kicker = null, ?string $imageBytes = null, bool $photoWash = true): string
    {
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($canvas, true);
        imagefilledrectangle($canvas, 0, 0, self::WIDTH, self::HEIGHT, $this->color($canvas, '#0B0B0C'));

        if ($imageBytes !== null) {
            if ($photoWash) {
                // A photograph reads well as a blurred wash.
                $this->drawWash($canvas, $imageBytes);
                $this->drawScrim($canvas);
            } else {
                // A logo does not — blurring it leaves a ghost of the glyph. Take
                // its colour instead and build a tinted ground from that.
                $this->drawTint($canvas, $imageBytes);
            }
        }

        // Mascot + wordmark.
        $markSize = 96;
        $this->drawMark($canvas, self::PAD, 62, $markSize);
        $this->drawText($canvas, 'monno', self::PAD + $markSize + 22, 62 + $markSize - 26, 46, '#FFFFFF', true);

        $this->drawSpectrumRule($canvas, self::PAD, 196, 7, 26, 6);

        if ($kicker !== null && $kicker !== '') {
            $this->drawText($canvas, $this->truncate($kicker, 52), self::PAD, 296, 26, '#C9CCD3');
        }

        $lines = $this->wrap($title, 46, 620, 4);
        $y = 362;
        foreach ($lines as $line) {
            $this->drawText($canvas, $line, self::PAD, $y, 46, '#FFFFFF', true);
            $y += 62;
        }

        $this->drawTile(
            $canvas,
            self::WIDTH - self::PAD - self::TILE,
            (int) ((self::HEIGHT - self::TILE) / 2),
            $imageBytes,
            ! $photoWash
        );

        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();
        imagedestroy($canvas);

        return $png;
    }

    /** A soft, darkened blow-up of the entity image, filling the canvas. */
    private function drawWash(GdImage $canvas, string $bytes): void
    {
        $source = $this->imageFromString($bytes);

        if ($source === null) {
            return;
        }

        // Blur cheaply, and hard: shrink it a long way, blur the small copy, then
        // scale it back up. At ~80px wide the shapes are gone and what is left is
        // the image's colour field — a wash, not a ghost of the artwork.
        $smallW = 80;
        $smallH = (int) round($smallW * self::HEIGHT / self::WIDTH);
        $small = imagecreatetruecolor($smallW, $smallH);
        imagealphablending($small, true);
        imagefilledrectangle($small, 0, 0, $smallW, $smallH, $this->color($small, '#0B0B0C'));

        $sw = imagesx($source);
        $sh = imagesy($source);
        $scale = max($smallW / $sw, $smallH / $sh);
        $dw = (int) ceil($sw * $scale);
        $dh = (int) ceil($sh * $scale);
        imagecopyresampled($small, $source, (int) (($smallW - $dw) / 2), (int) (($smallH - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
        imagedestroy($source);

        for ($i = 0; $i < 3; $i++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }

        imagecopyresampled($canvas, $small, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, $smallW, $smallH);
        imagedestroy($small);

        // Push it back so the text reads.
        $this->veil($canvas, 0, 0, self::WIDTH, self::HEIGHT, 72);
    }

    /** Darkens the left two-thirds so the copy always has contrast. */
    private function drawScrim(GdImage $canvas): void
    {        $bands = 60;
        $bandW = (int) ceil(self::WIDTH / $bands);

        for ($i = 0; $i < $bands; $i++) {
            $strength = 0.66 * (1 - ($i / $bands));
            $this->veil($canvas, $i * $bandW, 0, $bandW, self::HEIGHT, (int) round($strength * 100));
        }
    }

    /**
     * A designed ground built from the image's own colour: a soft vertical
     * gradient plus a glow behind the tile. Used when the image is a logo, where
     * a blurred wash would leave a ghost of the glyph.
     */
    private function drawTint(GdImage $canvas, string $bytes): void
    {
        $tint = $this->averageColor($bytes);
        $ink = [0x0B, 0x0B, 0x0C];

        $bands = 72;
        $bandH = (int) ceil(self::HEIGHT / $bands);
        for ($i = 0; $i < $bands; $i++) {
            $colour = $this->mixColor($canvas, $ink, $tint, 0.18 * (1 - $i / $bands));
            imagefilledrectangle($canvas, 0, $i * $bandH, self::WIDTH, $i * $bandH + $bandH, $colour);
        }

        $centreX = (int) (self::WIDTH * 0.74);
        $centreY = (int) (self::HEIGHT * 0.40);
        $steps = 48;
        $max = 860;

        for ($i = $steps; $i >= 1; $i--) {
            $radius = (int) ($max * $i / $steps);
            $strength = 0.36 * (1 - $i / $steps);
            $colour = imagecolorallocatealpha(
                $canvas,
                $tint[0],
                $tint[1],
                $tint[2],
                (int) round(127 * (1 - $strength)),
            );
            imagefilledellipse($canvas, $centreX, $centreY, $radius * 2, (int) ($radius * 1.5), $colour);
        }
    }

    /** The average colour of the image, ignoring transparent pixels. */
    private function averageColor(string $bytes): array
    {
        $image = $this->imageFromString($bytes);

        if ($image === null) {
            return [22, 22, 26];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $stepX = max(1, (int) ($width / 24));
        $stepY = max(1, (int) ($height / 24));
        $r = $g = $b = 0;
        $count = 0;

        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $rgba = imagecolorat($image, $x, $y);

                if ((($rgba >> 24) & 0x7F) > 100) {
                    continue; // near-transparent
                }

                $r += ($rgba >> 16) & 0xFF;
                $g += ($rgba >> 8) & 0xFF;
                $b += $rgba & 0xFF;
                $count++;
            }
        }

        imagedestroy($image);

        if ($count === 0) {
            return [22, 22, 26];
        }

        return [(int) ($r / $count), (int) ($g / $count), (int) ($b / $count)];
    }

    private function mixColor(GdImage $canvas, array $from, array $to, float $ratio): int
    {
        $ratio = max(0.0, min(1.0, $ratio));

        return (int) imagecolorallocate(
            $canvas,
            (int) round($from[0] + ($to[0] - $from[0]) * $ratio),
            (int) round($from[1] + ($to[1] - $from[1]) * $ratio),
            (int) round($from[2] + ($to[2] - $from[2]) * $ratio),
        );
    }

    private function veil(GdImage $canvas, int $x, int $y, int $w, int $h, int $percent): void
    {
        $alpha = (int) round(127 * (1 - min(100, max(0, $percent)) / 100));
        $colour = imagecolorallocatealpha($canvas, 0, 0, 0, $alpha);
        imagefilledrectangle($canvas, $x, $y, $x + $w, $y + $h, $colour);
    }

    private function drawMark(GdImage $canvas, int $x, int $y, int $size): void
    {
        if (! is_file($this->markPath)) {
            return;
        }

        $mark = $this->imageFromString((string) file_get_contents($this->markPath));

        if ($mark === null) {
            return;
        }

        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $mark, $x, $y, 0, 0, $size, $size, imagesx($mark), imagesy($mark));
        imagedestroy($mark);
    }

    private function drawTile(GdImage $canvas, int $x, int $y, ?string $imageBytes, bool $logoMode): void
    {
        $size = self::TILE;

        if ($logoMode) {
            // A logo filling the tile edge to edge would melt into the tinted
            // ground, so it sits on a dark plate instead.
            $plate = $this->solidRounded($size, self::TILE_RADIUS, '#141418');

            if ($imageBytes !== null) {
                $box = (int) round($size * 0.62);
                $logo = $this->roundedFromBytes($imageBytes, $box, (int) round($box * 0.2), false);

                if ($logo !== null) {
                    imagealphablending($plate, true);
                    imagecopy($plate, $logo, (int) (($size - $box) / 2), (int) (($size - $box) / 2), 0, 0, $box, $box);
                    imagedestroy($logo);
                }
            }

            imagealphablending($canvas, true);
            imagecopy($canvas, $plate, $x, $y, 0, 0, $size, $size);
            imagedestroy($plate);

            return;
        }

        $tile = $this->roundedFromBytes($imageBytes, $size, self::TILE_RADIUS, true)
            ?? $this->solidRounded($size, self::TILE_RADIUS, '#1A1A1F');

        imagealphablending($canvas, true);
        imagecopy($canvas, $tile, $x, $y, 0, 0, $size, $size);
        imagedestroy($tile);
    }

    /** A rounded, transparent-backed image: `cover` fills the box, otherwise it fits inside. */
    private function roundedFromBytes(string $bytes, int $size, int $radius, bool $cover): ?GdImage
    {
        $source = $this->imageFromString($bytes);

        if ($source === null) {
            return null;
        }

        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $size, $size, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);

        $sw = imagesx($source);
        $sh = imagesy($source);

        if ($cover) {
            $side = min($sw, $sh);
            imagecopyresampled($image, $source, 0, 0, (int) (($sw - $side) / 2), (int) (($sh - $side) / 2), $size, $size, $side, $side);
        } else {
            $scale = min($size / $sw, $size / $sh);
            $dw = (int) round($sw * $scale);
            $dh = (int) round($sh * $scale);
            imagecopyresampled($image, $source, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
        }

        imagedestroy($source);
        $this->roundCorners($image, $size, $radius);

        return $image;
    }

    private function solidRounded(int $size, int $radius, string $hex): GdImage
    {
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $size, $size, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);
        imagefilledrectangle($image, 0, 0, $size, $size, $this->color($image, $hex));
        $this->roundCorners($image, $size, $radius);

        return $image;
    }

    /** Clears everything outside a rounded rectangle, with a one-pixel feather. */
    private function roundCorners(GdImage $tile, int $size, int $radius): void
    {
        imagealphablending($tile, false);
        $clear = imagecolorallocatealpha($tile, 0, 0, 0, 127);
        $feather = imagecolorallocatealpha($tile, 0, 0, 0, 70);

        for ($j = 0; $j < $radius; $j++) {
            for ($i = 0; $i < $radius; $i++) {
                $dx = $radius - 1 - $i;
                $dy = $radius - 1 - $j;
                $distance = sqrt($dx * $dx + $dy * $dy);

                if ($distance <= $radius - 1) {
                    continue;
                }

                $colour = $distance <= $radius ? $feather : $clear;

                imagesetpixel($tile, $i, $j, $colour);
                imagesetpixel($tile, $size - 1 - $i, $j, $colour);
                imagesetpixel($tile, $i, $size - 1 - $j, $colour);
                imagesetpixel($tile, $size - 1 - $i, $size - 1 - $j, $colour);
            }
        }

        imagealphablending($tile, true);
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

    private function imageFromString(string $bytes): ?GdImage
    {
        try {
            $image = @imagecreatefromstring($bytes);
        } catch (Throwable) {
            return null;
        }

        return $image instanceof GdImage ? $image : null;
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
