<?php

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files that must never be public — player documents, board minutes, transaction
 * receipts — on the
 * private `local` disk (storage/app/private).
 *
 * Nothing here produces a URL: the public `/media/{path}` route only reads
 * the public disk, and the framework's own local-disk route needs a signed
 * URL this app never issues. A file is reached only through a controller
 * action that has already checked who is asking.
 *
 * Files are stored unmodified (scans must stay legible) under a random name;
 * the caller keeps the original name for downloads.
 */
class PrivateFileStorage
{
    public const DISK = 'local';

    /**
     * Extensions safe to render in a browser tab, mapped to the exact
     * Content-Type to send. Never let the storage driver sniff it: a stored
     * file can be a legacy import whose bytes don't match its name (e.g. an
     * HTML file saved with a .jpg extension), and finfo-based sniffing would
     * then serve it as text/html — inline, on the app's own origin, where it
     * would run as script. Only an extension on this list is ever shown
     * inline, and only with this exact type; anything else is downloaded.
     */
    public const INLINE_MIME_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    /**
     * @return array{path: string, original_name: string, mime: string, size: int}
     */
    public function store(UploadedFile $file, string $directory): array
    {
        $path = $file->store(trim($directory, '/'), self::DISK);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The file could not be stored.');
        }

        return [
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName() ?: basename($path), 0, 255),
            'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size' => (int) $file->getSize(),
        ];
    }

    public function exists(?string $path): bool
    {
        return $this->isSafe($path) && Storage::disk(self::DISK)->exists($path);
    }

    public function delete(?string $path): void
    {
        if ($this->isSafe($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function deleteDirectory(string $directory): void
    {
        if ($this->isSafe($directory)) {
            Storage::disk(self::DISK)->deleteDirectory($directory);
        }
    }

    /** Shown in the browser tab (PDF viewer, image). */
    public function inline(string $path, string $name, ?string $mime = null): StreamedResponse
    {
        abort_unless($this->exists($path), 404);

        return Storage::disk(self::DISK)->response($path, $name, array_filter([
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]));
    }

    /** Saved to disk under its original name. */
    public function download(string $path, string $name): StreamedResponse
    {
        abort_unless($this->exists($path), 404);

        return Storage::disk(self::DISK)->download($path, $name, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Serve a stored file the safe way: inline with the exact Content-Type
     * from INLINE_MIME_TYPES when its extension is on that list, or a forced
     * download otherwise. Every "view this private file" route should call
     * this rather than inline()/download() directly, so a stored file whose
     * bytes don't match its extension — a mislabelled legacy import, say —
     * is never sniffed by the storage driver and rendered as something else.
     */
    public function serve(string $path, string $name): StreamedResponse
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::INLINE_MIME_TYPES[$extension] ?? null;

        return $mime !== null
            ? $this->inline($path, $name, $mime)
            : $this->download($path, $name);
    }

    /** Only plain relative paths inside the disk: no climbing out, no absolute paths. */
    private function isSafe(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        $path = str_replace('\\', '/', $path);

        return ! str_starts_with($path, '/')
            && ! preg_match('#^[A-Za-z]:#', $path)
            && ! in_array('..', explode('/', $path), true);
    }
}
