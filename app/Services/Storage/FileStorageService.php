<?php

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
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
        $directory = trim($directory, '/');
        $extension = $this->normaliseExtension($file);
        $binary = $this->encodeImage($file, $maxWidth, $extension);

        if ($binary !== null) {
            $path = $directory.'/'.Str::uuid()->toString().'.'.$extension;
            Storage::disk($this->disk)->put($path, $binary);
        } else {
            // Processing unavailable (e.g. corrupt file / missing GD) — keep original.
            $path = $file->store($directory, $this->disk);
        }

        return $this->describe($path);
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

    private function encodeImage(UploadedFile $file, int $maxWidth, string $extension): ?string
    {
        if (! class_exists(ImageManager::class)) {
            return null;
        }

        try {
            $image = ImageManager::gd()->read($file->getRealPath());

            if ($image->width() > $maxWidth) {
                $image->scaleDown(width: $maxWidth);
            }

            return (string) $image->encodeByExtension($extension, quality: 80);
        } catch (Throwable) {
            return null;
        }
    }
}
