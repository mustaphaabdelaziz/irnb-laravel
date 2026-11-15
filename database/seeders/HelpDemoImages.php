<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Services\Storage\FileStorageService;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Http\UploadedFile;

/**
 * Neutral generated badges, activity photos and a demo PDF for the help screenshots: plain
 * shapes in a few colours, no text (GD cannot shape Arabic) and no real club's emblem. Written only to the
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

        $base = tempnam(sys_get_temp_dir(), 'logo');
        $tmp = $base.'.png';
        imagepng($img, $tmp);
        imagedestroy($img);

        try {
            $stored = app(FileStorageService::class)->storeImage(new UploadedFile($tmp, 'logo.png', 'image/png', null, true), 'opponents', 256);
        } finally {
            @unlink($tmp);
            @unlink($base);
        }

        return ['url' => $stored['url'], 'filename' => $stored['filename']];
    }

    /**
     * A neutral 1600 x 1000 scene (no text, nothing that reads as a real place or club),
     * stored with its thumbnail exactly like an upload on the activity page.
     *
     * @param  'field'|'beach'|'hall'|'pool'  $scene
     * @return array{path: string, thumb_path: string}|null
     */
    public static function photo(Activity $activity, string $scene, int $variant = 0): ?array
    {
        if (! HelpDemoSeeder::onThrowawayStorage()) {
            return null;
        }

        $img = imagecreatetruecolor(1600, 1000);
        mt_srand(crc32($scene.$variant));
        try {
            $shift = [mt_rand(-15, 15), mt_rand(-15, 15), mt_rand(-15, 15)];
            $c = static fn (int $r, int $g, int $b): int => imagecolorallocate(
                $img,
                max(0, min(255, $r + $shift[0])),
                max(0, min(255, $g + $shift[1])),
                max(0, min(255, $b + $shift[2])),
            );

            match ($scene) {
                'beach' => self::beach($img, $c),
                'hall' => self::hall($img, $c),
                'pool' => self::pool($img, $c),
                default => self::field($img, $c),
            };
        } finally {
            mt_srand();
        }

        $base = tempnam(sys_get_temp_dir(), 'photo');
        $tmp = $base.'.jpg';
        imagejpeg($img, $tmp, 85);
        imagedestroy($img);

        try {
            $sizes = app(FileStorageService::class)->storeImageVariants(
                new UploadedFile($tmp, "{$scene}.jpg", 'image/jpeg', null, true),
                [1600 => $activity->photoDirectory(), 400 => $activity->photoDirectory().'/thumbs'],
            );
        } finally {
            @unlink($tmp);
            @unlink($base);
        }

        return ['path' => $sizes[1600]['path'], 'thumb_path' => $sizes[400]['path']];
    }

    /**
     * A tiny valid one-page PDF (an empty page with one rectangle), kept on the private disk
     * the way an uploaded activity file is.
     *
     * @return array{path: string, original_name: string, mime: string, size: int}|null
     */
    public static function pdf(Activity $activity, string $originalName): ?array
    {
        if (! HelpDemoSeeder::onThrowawayStorage()) {
            return null;
        }

        $content = "0.8 0.85 0.95 rg\n72 600 200 120 re f\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << >> >>',
            '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
        }
        $size = count($objects) + 1;
        $xref = strlen($pdf);
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        $tmp = tempnam(sys_get_temp_dir(), 'demo');
        file_put_contents($tmp, $pdf);

        try {
            return app(PrivateFileStorage::class)->store(
                new UploadedFile($tmp, $originalName, 'application/pdf', null, true),
                $activity->fileDirectory(),
            );
        } finally {
            @unlink($tmp);
        }
    }

    private static function person(\GdImage $img, int $x, int $y, int $shirt, int $skin, int $scale = 1): void
    {
        $s = 14 * $scale;
        imagefilledrectangle($img, $x - $s, $y, $x + $s, $y + $s * 3, $shirt);
        imagefilledellipse($img, $x, $y, $s * 2, $s * 2, $shirt);
        imagefilledellipse($img, $x, $y - $s * 2, (int) ($s * 1.6), (int) ($s * 1.6), $skin);
    }

    private static function sky(\GdImage $img, \Closure $c, int $height): void
    {
        for ($y = 0; $y < $height; $y++) {
            $t = $y / $height;
            imageline($img, 0, $y, 1599, $y, $c((int) (120 + 90 * $t), (int) (180 + 55 * $t), 235));
        }
    }

    private static function field(\GdImage $img, \Closure $c): void
    {
        self::sky($img, $c, 360);
        $white = imagecolorallocate($img, 245, 245, 245);
        imagefilledrectangle($img, 0, 360, 1599, 999, $c(46, 140, 58));
        imagesetthickness($img, 6);
        imagerectangle($img, 120, 460, 1480, 940, $white);
        imageline($img, 800, 460, 800, 940, $white);
        imageellipse($img, 800, 700, 220, 220, $white);
        imagerectangle($img, 700, 400, 900, 460, $white);
        imagesetthickness($img, 1);
        $shirts = [$c(220, 50, 50), $c(40, 90, 200)];
        $skin = $c(225, 180, 140);
        $count = mt_rand(6, 8);
        for ($i = 0; $i < $count; $i++) {
            self::person($img, mt_rand(200, 1400), mt_rand(560, 780), $shirts[$i % 2], $skin, 2);
        }
    }

    private static function beach(\GdImage $img, \Closure $c): void
    {
        self::sky($img, $c, 420);
        imagefilledellipse($img, mt_rand(1100, 1400), 140, 140, 140, $c(255, 215, 80));
        imagefilledrectangle($img, 0, 420, 1599, 560, $c(40, 120, 190));
        imagefilledrectangle($img, 0, 560, 1599, 999, $c(235, 210, 160));
        $shirts = [$c(230, 120, 40), $c(60, 160, 120), $c(150, 70, 170)];
        $skin = $c(220, 175, 135);
        $bag = $c(80, 60, 40);
        for ($i = 0; $i < 7; $i++) {
            $x = mt_rand(150, 1450);
            $y = mt_rand(640, 800);
            self::person($img, $x, $y, $shirts[$i % 3], $skin, 2);
            imagefilledrectangle($img, $x + 40, $y + 40, $x + 80, $y + 100, $bag);
        }
    }

    private static function hall(\GdImage $img, \Closure $c): void
    {
        imagefilledrectangle($img, 0, 0, 1599, 620, $c(225, 220, 205));
        imagefilledrectangle($img, 0, 620, 1599, 999, $c(160, 125, 90));
        imagefilledrectangle($img, 520, 90, 1080, 380, $c(250, 250, 250));
        imagesetthickness($img, 10);
        imagerectangle($img, 520, 90, 1080, 380, $c(90, 90, 100));
        imagesetthickness($img, 1);
        $shirts = [$c(60, 90, 160), $c(170, 60, 80), $c(70, 130, 90), $c(200, 150, 50)];
        $skin = $c(225, 180, 140);
        $count = mt_rand(5, 6);
        $step = 1200 / $count;
        for ($i = 0; $i < $count; $i++) {
            self::person($img, (int) (200 + $step * $i + mt_rand(-20, 20)), 520, $shirts[$i % 4], $skin, 2);
        }
        imagefilledrectangle($img, 150, 680, 1450, 760, $c(110, 75, 45));
        imagefilledrectangle($img, 190, 760, 230, 920, $c(90, 60, 35));
        imagefilledrectangle($img, 1370, 760, 1410, 920, $c(90, 60, 35));
    }

    private static function pool(\GdImage $img, \Closure $c): void
    {
        imagefilledrectangle($img, 0, 0, 1599, 999, $c(60, 170, 215));
        $white = imagecolorallocate($img, 240, 248, 252);
        imagesetthickness($img, 8);
        foreach ([1, 2, 3, 4] as $lane) {
            imageline($img, 0, 140 + $lane * 120, 1599, 140 + $lane * 120, $lane % 2 ? $c(230, 80, 60) : $white);
        }
        imagesetthickness($img, 1);
        imagefilledrectangle($img, 0, 720, 1599, 999, $c(215, 215, 205));
        imagefilledrectangle($img, 0, 700, 1599, 724, $white);
        $shirts = [$c(230, 90, 60), $c(40, 100, 180), $c(240, 200, 60)];
        $skin = $c(220, 175, 135);
        for ($i = 0; $i < 7; $i++) {
            self::person($img, mt_rand(120, 1480), mt_rand(760, 830), $shirts[$i % 3], $skin, 2);
        }
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
