<?php

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Centralised local-disk file storage for uploads (player photos, equipment
 * images, receipts, branding). Images are resized/compressed when possible and
 * fall back to storing the original file untouched if processing is unavailable.
 *
 * Every method returns an array shaped: ['path' => ..., 'filename' => ..., 'url' => ...]
 * where `filename` is the disk-relative path (persist this to delete later) and
 * `url` is the public URL to render in the UI.
 */
class FileStorageService
{
    private string $disk = 'public';

    /**
     * Store an uploaded image, scaling it down to $maxWidth and compressing it.
     *
     * @return array{path: string, filename: string, url: string}
     */
    public function storeImage(UploadedFile $file, string $directory, int $maxWidth = 1024): array
    {
        return $this->storeImageVariants($file, [$maxWidth => $directory])[$maxWidth];
    }

    /**
     * Store several sizes of one uploaded image, decoding it only once: the
     * largest width is scaled and encoded first, then that same image is
     * scaled further down for each smaller width. Images are never scaled up.
     *
     * If processing is unavailable the original is stored once (in the
     * largest width's directory) and returned for every width.
     *
     * @param  array<int, string>  $targets  max width => directory, e.g. [1600 => 'a/5', 400 => 'a/5/thumbs']
     * @return array<int, array{path: string, filename: string, url: string}> keyed by width, largest first
     */
    public function storeImageVariants(UploadedFile $file, array $targets): array
    {
        krsort($targets);
        $extension = $this->normaliseExtension($file);
        $binaries = $this->encodeImage($file, array_keys($targets), $extension);

        if ($binaries === null) {
            // Processing unavailable (e.g. corrupt file / missing GD) — keep original.
            $original = $this->describe($file->store(trim((string) reset($targets), '/'), $this->disk));

            return array_map(fn () => $original, $targets);
        }

        $stored = [];
        foreach ($targets as $width => $directory) {
            $path = trim($directory, '/').'/'.Str::uuid()->toString().'.'.$extension;
            Storage::disk($this->disk)->put($path, $binaries[$width]);
            $stored[$width] = $this->describe($path);
        }

        return $stored;
    }

    /**
     * Store an arbitrary file (receipts may be PDF or image) without processing.
     *
     * @return array{path: string, filename: string, url: string}
     */
    public function storeFile(UploadedFile $file, string $directory): array
    {
        $path = $file->store(trim($directory, '/'), $this->disk);

        return $this->describe($path);
    }

    /**
     * Delete a previously stored file by its disk-relative path.
     * Safely ignores nulls, absolute URLs, and paths that no longer exist.
     */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        if (Str::startsWith($path, ['http://', 'https://', '//'])) {
            return;
        }

        if (Storage::disk($this->disk)->exists($path)) {
            Storage::disk($this->disk)->delete($path);
        }
    }

    /**
     * @return array{path: string, filename: string, url: string}
     */
    private function describe(string $path): array
    {
        return [
            'path' => $path,
            'filename' => $path,
            // Host-relative /media path: resolves on the web dev server AND the
            // desktop app's dynamic-port window (served by the /media/{path} route).
            'url' => '/media/'.ltrim($path, '/'),
        ];
    }

    private function normaliseExtension(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'jpg'));

        return match ($extension) {
            'jpeg' => 'jpg',
            '' => 'jpg',
            default => $extension,
        };
    }

    /**
     * @param  int[]  $widths  descending
     * @return array<int, string>|null encoded binary per width, or null when processing is unavailable
     */
    private function encodeImage(UploadedFile $file, array $widths, string $extension): ?array
    {
        if (! class_exists(ImageManager::class)) {
            return null;
        }

        // intervention/image is pinned to 3.0.0, which has no encodeByExtension().
        $encoder = match ($extension) {
            'jpg' => new JpegEncoder(quality: 80),
            'png' => new PngEncoder,
            'webp' => new WebpEncoder(quality: 80),
            default => null,
        };

        if ($encoder === null) {
            return null;
        }

        try {
            $image = ImageManager::gd()->read($file->getRealPath());

            // Re-encoding drops EXIF, so turn the photo upright first.
            // With ext-exif, intervention 3.0.0 has already done it while decoding.
            if (! function_exists('exif_read_data')) {
                $this->orient($image, $this->jpegOrientation($file->getRealPath()));
            }

            $binaries = [];
            foreach ($widths as $width) {
                if ($image->width() > $width) {
                    $image->scaleDown(width: $width);
                }

                $binaries[$width] = (string) $image->encode($encoder);
            }

            return $binaries;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Apply an EXIF orientation (1–8) so the image displays upright.
     * intervention 3.0.0: rotate() turns counter-clockwise, flop() mirrors
     * left↔right, flip() mirrors top↕bottom.
     */
    private function orient(ImageInterface $image, int $orientation): void
    {
        match ($orientation) {
            2 => $image->flop(),
            3 => $image->rotate(180),
            4 => $image->flip(),
            5 => $image->rotate(270)->flop(),  // transpose
            6 => $image->rotate(270),          // 90° clockwise
            7 => $image->rotate(90)->flop(),   // transverse
            8 => $image->rotate(90),           // 90° counter-clockwise
            default => $image,
        };
    }

    /**
     * Read the EXIF Orientation tag of a JPEG without ext-exif: walk the
     * segments up to the image data, find APP1 "Exif\0\0", then the TIFF
     * IFD0 entry 0x0112. Anything unexpected (not a JPEG, no tag) means 1.
     */
    private function jpegOrientation(string $path): int
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return 1;
        }

        try {
            if (fread($handle, 2) !== "\xFF\xD8") {
                return 1;
            }

            while (strlen($marker = (string) fread($handle, 4)) === 4 && $marker[0] === "\xFF") {
                $type = ord($marker[1]);
                $length = unpack('n', $marker, 2)[1];

                // Start of scan / end of image: no metadata past this point.
                if ($type === 0xDA || $type === 0xD9 || $length < 2) {
                    return 1;
                }

                $segment = $length > 2 ? (string) fread($handle, $length - 2) : '';
                if ($type === 0xE1 && str_starts_with($segment, "Exif\0\0")) {
                    return $this->tiffOrientation(substr($segment, 6));
                }
            }

            return 1;
        } finally {
            fclose($handle);
        }
    }

    private function tiffOrientation(string $tiff): int
    {
        $littleEndian = match (substr($tiff, 0, 2)) {
            'II' => true,
            'MM' => false,
            default => null,
        };
        if ($littleEndian === null) {
            return 1;
        }

        $short = fn (int $at): ?int => strlen($tiff) >= $at + 2 ? unpack($littleEndian ? 'v' : 'n', $tiff, $at)[1] : null;
        $long = fn (int $at): ?int => strlen($tiff) >= $at + 4 ? unpack($littleEndian ? 'V' : 'N', $tiff, $at)[1] : null;

        if ($short(2) !== 42 || ($ifd = $long(4)) === null) {
            return 1;
        }

        $entries = $short($ifd) ?? 0;
        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($short($entry) === 0x0112) {
                $value = $short($entry + 8);

                return $value !== null && $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }
}
