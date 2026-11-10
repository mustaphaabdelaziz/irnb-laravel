<?php

namespace Database\Seeders;

use App\Services\Storage\FileStorageService;
use Illuminate\Http\UploadedFile;

/**
 * Neutral generated badges for the help screenshots: plain shapes in two colours,
 * no text (GD cannot shape Arabic) and no real club's emblem. Written only to the
 * screenshot run's throwaway storage, through the same service as an upload.
 */
final class HelpDemoImages
{
    /** @return array{url: string, filename: string}|null */
    public static function logo(string $primaryHex, string $secondaryHex, string $shape): ?array
    {
        if (! HelpDemoSeeder::onThrowawayStorage()) {
            return null;
        }

        $size = 256;
        $img = imagecreatetruecolor($size, $size);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        $primary = self::colour($img, $primaryHex);
        $secondary = self::colour($img, $secondaryHex);
        $white = imagecolorallocate($img, 255, 255, 255);

        match ($shape) {
            'circle' => self::circle($img, $primary, $secondary, $white),
            'diamond' => self::diamond($img, $primary, $secondary, $white),
            default => self::shield($img, $primary, $secondary, $white),
        };

        $tmp = tempnam(sys_get_temp_dir(), 'logo').'.png';
        imagepng($img, $tmp);
        imagedestroy($img);

        try {
            $stored = app(FileStorageService::class)->storeImage(new UploadedFile($tmp, 'logo.png', 'image/png', null, true), 'opponents', 256);
        } finally {
            @unlink($tmp);
        }

        return ['url' => $stored['url'], 'filename' => $stored['filename']];
    }

    private static function colour(\GdImage $img, string $hex): int
    {
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');

        return imagecolorallocate($img, $r, $g, $b);
    }

    private static function shield(\GdImage $img, int $primary, int $secondary, int $white): void
    {
        $outline = [40, 30, 216, 30, 216, 130, 128, 230, 40, 130];
        imagefilledpolygon($img, $outline, $primary);
        imagefilledpolygon($img, [128, 30, 216, 30, 216, 130, 128, 230], $secondary);
        imagesetthickness($img, 8);
        imagepolygon($img, $outline, $white);
        imagefilledellipse($img, 128, 110, 70, 70, $white);
    }

    private static function circle(\GdImage $img, int $primary, int $secondary, int $white): void
    {
        imagefilledellipse($img, 128, 128, 220, 220, $primary);
        imagefilledrectangle($img, 18, 108, 238, 148, $secondary);
        imagesetthickness($img, 8);
        imageellipse($img, 128, 128, 220, 220, $white);
        imagefilledpolygon($img, [128, 80, 140, 116, 178, 116, 147, 138, 158, 176, 128, 153, 98, 176, 109, 138, 78, 116, 116, 116], $white);
    }

    private static function diamond(\GdImage $img, int $primary, int $secondary, int $white): void
    {
        $outline = [128, 18, 238, 128, 128, 238, 18, 128];
        imagefilledpolygon($img, $outline, $primary);
        imagefilledpolygon($img, [128, 70, 186, 128, 128, 186, 70, 128], $secondary);
        imagesetthickness($img, 8);
        imagepolygon($img, $outline, $white);
    }
}
