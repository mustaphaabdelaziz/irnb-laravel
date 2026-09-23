<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Uploaded transaction receipts used to sit on the public disk, readable by
     * anyone through /media (and the web server's public/storage symlink). Move
     * every one of them to the private disk, where only the authenticated
     * transactions.receipt-file.show route can read them. Same algorithm as the
     * board-minutes migration (2026_09_24_100004).
     *
     * Idempotent, because the desktop build re-runs an unrecorded migration on
     * the next boot: a file is (re)copied while the private copy is missing or
     * differs in size, the public copy is deleted only once the private one
     * matches it, and a row is re-pointed only when its private file exists.
     * A row whose file is on neither disk, or whose link is external, is left
     * exactly as it was.
     */
    public function up(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        $public = Storage::disk('public');
        $private = Storage::disk('local');

        $rows = DB::table('transactions')
            ->where(fn ($query) => $query->whereNotNull('receipt_filename')->orWhereNotNull('receipt_url'))
            ->orderBy('id')
            ->get(['id', 'receipt_url', 'receipt_filename']);

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

            DB::table('transactions')->where('id', $row->id)->update([
                'receipt_filename' => $path,
                'receipt_url' => null,
            ]);
        }
    }

    /** Put the files back on the public disk with their /media URL. */
    public function down(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        $public = Storage::disk('public');
        $private = Storage::disk('local');

        foreach (DB::table('transactions')->whereNotNull('receipt_filename')->get(['id', 'receipt_filename']) as $row) {
            $path = $row->receipt_filename;

            if (! str_starts_with($path, 'receipts/') || ! $private->exists($path)) {
                continue;
            }

            $this->copy($private, $public, $path);
            $private->delete($path);

            DB::table('transactions')->where('id', $row->id)->update(['receipt_url' => '/media/'.$path]);
        }
    }

    /**
     * The file's path inside a disk: the filename when it is a receipts/ path,
     * else the path inside an old /media or /storage URL; null if neither.
     */
    private function relativePath(object $row): ?string
    {
        $path = ltrim(str_replace('\\', '/', (string) $row->receipt_filename), '/');

        if (! str_starts_with($path, 'receipts/')) {
            $path = null;

            if ($row->receipt_url
                && preg_match('#(?:^|/)(?:media|storage)/(receipts/.+)$#i', (string) $row->receipt_url, $match)) {
                $path = $match[1];
            }
        }

        if ($path === null || in_array('..', explode('/', $path), true)) {
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
