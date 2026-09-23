<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Board minutes used to sit on the public disk, readable by anyone through
     * /media (and the web server's public/storage symlink). Move every one of
     * them to the private disk, where only the authenticated
     * board.meetings.attachment.show route can read them.
     *
     * Idempotent, because the desktop build re-runs an unrecorded migration on
     * the next boot: a file is (re)copied while the private copy is missing or
     * differs in size, the public copy is deleted only once the private one
     * matches it, and a row is re-pointed only when its private file exists.
     * A row whose file is on neither disk is left exactly as it was.
     */
    public function up(): void
    {
        if (! Schema::hasTable('board_meetings')) {
            return;
        }

        $public = Storage::disk('public');
        $private = Storage::disk('local');

        $rows = DB::table('board_meetings')
            ->where(fn ($query) => $query->whereNotNull('attachment_filename')->orWhereNotNull('attachment_url'))
            ->orderBy('id')
            ->get(['id', 'attachment_url', 'attachment_filename']);

        foreach ($rows as $row) {
            $path = $this->relativePath($row);

            if ($path === null) {
                continue;
            }

            if ($public->exists($path) && (! $private->exists($path) || $private->size($path) !== $public->size($path))) {
                $this->copy($public, $private, $path);
            }

            if (! $private->exists($path)) {
                continue;
            }

            if ($public->exists($path) && $public->size($path) === $private->size($path)) {
                $public->delete($path);
            }

            DB::table('board_meetings')->where('id', $row->id)->update([
                'attachment_filename' => $path,
                'attachment_url' => null,
            ]);
        }
    }

    /** Put the files back on the public disk with their /media URL. */
    public function down(): void
    {
        if (! Schema::hasTable('board_meetings')) {
            return;
        }

        $public = Storage::disk('public');
        $private = Storage::disk('local');

        foreach (DB::table('board_meetings')->whereNotNull('attachment_filename')->get(['id', 'attachment_filename']) as $row) {
            $path = $row->attachment_filename;

            if (! str_starts_with($path, 'minutes/') || ! $private->exists($path)) {
                continue;
            }

            $this->copy($private, $public, $path);
            $private->delete($path);

            DB::table('board_meetings')->where('id', $row->id)->update(['attachment_url' => '/media/'.$path]);
        }
    }

    /** The file's path inside a disk, from the filename or from an old URL; null if not a minutes file. */
    private function relativePath(object $row): ?string
    {
        $path = $row->attachment_filename;

        if (! $path && $row->attachment_url
            && preg_match('#(?:^|/)(?:media|storage)/(minutes/.+)$#i', (string) $row->attachment_url, $match)) {
            $path = $match[1];
        }

        if (! $path) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', (string) $path), '/');

        if (! str_starts_with($path, 'minutes/') || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return $path;
    }

    private function copy(Filesystem $from, Filesystem $to, string $path): void
    {
        $stream = $from->readStream($path);

        try {
            $to->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
};
