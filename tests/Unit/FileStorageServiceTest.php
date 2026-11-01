<?php

namespace Tests\Unit;

use App\Services\Storage\FileStorageService;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Uploads are decoded and re-encoded (resize + compress), which drops EXIF.
 * Without the `exif` extension (absent from the bundled desktop PHP)
 * intervention/image 3.0.0 does not auto-orient, so the service has to turn
 * phone photos upright itself before the tag is lost.
 */
class FileStorageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function service(): FileStorageService
    {
        return app(FileStorageService::class);
    }

    /** 200x100 JPEG, left half red and right half blue, tagged with $orientation. */
    private function orientedJpeg(int $orientation): UploadedFile
    {
        $gd = imagecreatetruecolor(200, 100);
        imagefilledrectangle($gd, 0, 0, 99, 99, imagecolorallocate($gd, 255, 0, 0));
        imagefilledrectangle($gd, 100, 0, 199, 99, imagecolorallocate($gd, 0, 0, 255));
        ob_start();
        imagejpeg($gd, null, 95);
        $jpeg = ob_get_clean();

        // Minimal little-endian TIFF: header, IFD0 with one entry (0x0112 SHORT = $orientation).
        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 1)
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0)
            .pack('V', 0);
        $app1 = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\0\0".$tiff;

        // Splice the APP1 segment straight after the SOI marker.
        return UploadedFile::fake()->createWithContent('phone.jpg', "\xFF\xD8".$app1.substr($jpeg, 2));
    }

    private function stored(string $path): GdImage
    {
        return imagecreatefromstring(Storage::disk('public')->get($path));
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(GdImage $gd, int $x, int $y): array
    {
        $c = imagecolorat($gd, $x, $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }

    private function assertReddish(array $rgb, string $where): void
    {
        $this->assertTrue($rgb[0] > 200 && $rgb[2] < 60, "expected red at {$where}, got ".implode(',', $rgb));
    }

    private function assertBluish(array $rgb, string $where): void
    {
        $this->assertTrue($rgb[2] > 200 && $rgb[0] < 60, "expected blue at {$where}, got ".implode(',', $rgb));
    }

    #[Test]
    public function a_photo_tagged_orientation_6_is_turned_upright_clockwise(): void
    {
        $stored = $this->service()->storeImage($this->orientedJpeg(6), 'photos', 1024);
        $gd = $this->stored($stored['path']);

        $this->assertSame([100, 200], [imagesx($gd), imagesy($gd)]);
        // Rotated 90° clockwise: the left (red) half ends up on top.
        $this->assertReddish($this->rgb($gd, 50, 20), 'top');
        $this->assertBluish($this->rgb($gd, 50, 180), 'bottom');
    }

    #[Test]
    public function a_photo_tagged_orientation_8_is_turned_upright_counter_clockwise(): void
    {
        $stored = $this->service()->storeImage($this->orientedJpeg(8), 'photos', 1024);
        $gd = $this->stored($stored['path']);

        $this->assertSame([100, 200], [imagesx($gd), imagesy($gd)]);
        $this->assertBluish($this->rgb($gd, 50, 20), 'top');
        $this->assertReddish($this->rgb($gd, 50, 180), 'bottom');
    }

    #[Test]
    public function a_photo_tagged_orientation_1_is_left_as_it_is(): void
    {
        $stored = $this->service()->storeImage($this->orientedJpeg(1), 'photos', 1024);
        $gd = $this->stored($stored['path']);

        $this->assertSame([200, 100], [imagesx($gd), imagesy($gd)]);
        $this->assertReddish($this->rgb($gd, 20, 50), 'left');
    }

    #[Test]
    public function orientation_is_applied_before_scaling(): void
    {
        // Upright it is 100 wide, so a 150 cap must not shrink it.
        $stored = $this->service()->storeImage($this->orientedJpeg(6), 'photos', 150);
        $gd = $this->stored($stored['path']);

        $this->assertSame([100, 200], [imagesx($gd), imagesy($gd)]);
    }

    #[Test]
    public function a_transparent_png_keeps_its_alpha(): void
    {
        $gd = imagecreatetruecolor(300, 200);
        imagealphablending($gd, false);
        imagesavealpha($gd, true);
        imagefill($gd, 0, 0, imagecolorallocatealpha($gd, 0, 0, 0, 127));
        imagefilledrectangle($gd, 100, 50, 199, 149, imagecolorallocatealpha($gd, 0, 128, 0, 0));
        ob_start();
        imagepng($gd);
        $png = ob_get_clean();

        $stored = $this->service()->storeImage(UploadedFile::fake()->createWithContent('logo.png', $png), 'logos', 150);
        $this->assertStringEndsWith('.png', $stored['path']);

        $out = $this->stored($stored['path']);
        $this->assertSame(150, imagesx($out));
        $this->assertSame(127, (imagecolorat($out, 2, 2) >> 24) & 0x7F, 'the transparent corner stays transparent');
        $this->assertSame(0, (imagecolorat($out, 75, 50) >> 24) & 0x7F, 'the opaque centre stays opaque');
    }

    #[Test]
    public function a_webp_upload_is_stored_as_webp_and_resized(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD was built without WebP support.');
        }

        $stored = $this->service()->storeImage(UploadedFile::fake()->image('photo.webp', 800, 400), 'photos', 400);

        $this->assertStringEndsWith('.webp', $stored['path']);
        $info = getimagesizefromstring(Storage::disk('public')->get($stored['path']));
        $this->assertSame('image/webp', $info['mime']);
        $this->assertSame([400, 200], [$info[0], $info[1]]);
    }

    #[Test]
    public function variants_decode_once_and_store_each_width_in_its_own_folder(): void
    {
        $stored = $this->service()->storeImageVariants($this->orientedJpeg(6), [400 => 'a/thumbs', 1600 => 'a']);

        $this->assertSame([1600, 400], array_keys($stored));
        $this->assertStringStartsWith('a/thumbs/', $stored[400]['path']);
        $this->assertMatchesRegularExpression('#^a/[^/]+\.jpg$#', $stored[1600]['path']);
        $this->assertSame('/media/'.$stored[1600]['path'], $stored[1600]['url']);

        $large = $this->stored($stored[1600]['path']);
        $this->assertSame([100, 200], [imagesx($large), imagesy($large)], 'small images are never scaled up');

        $wide = $this->service()->storeImageVariants(UploadedFile::fake()->image('wide.jpg', 2400, 1200), [1600 => 'b', 400 => 'b/thumbs']);
        $this->assertSame(1600, getimagesizefromstring(Storage::disk('public')->get($wide[1600]['path']))[0]);
        $this->assertSame(400, getimagesizefromstring(Storage::disk('public')->get($wide[400]['path']))[0]);
    }

    #[Test]
    public function variants_fall_back_to_one_copy_of_the_original_when_it_cannot_be_processed(): void
    {
        $broken = UploadedFile::fake()->createWithContent('broken.jpg', 'not really a jpeg');

        $stored = $this->service()->storeImageVariants($broken, [1600 => 'c', 400 => 'c/thumbs']);

        $this->assertSame($stored[1600], $stored[400]);
        $this->assertStringStartsWith('c/', $stored[1600]['path']);
        $this->assertSame('not really a jpeg', Storage::disk('public')->get($stored[1600]['path']));
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }
}
