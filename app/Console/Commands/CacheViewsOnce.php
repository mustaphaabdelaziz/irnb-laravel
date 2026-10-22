<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ViewCacheCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The desktop shell runs `artisan optimize` before every launch, and its
 * view:cache step clears and rewrites every compiled Blade file — the slowest
 * part of startup on a PC whose antivirus scans each written file (~3–6 s).
 * The compiled views live in the user's data folder and survive between
 * launches, so on desktop this only recompiles when the views themselves
 * changed (an app update). Elsewhere it behaves exactly like view:cache.
 */
#[AsCommand(name: 'view:cache')]
class CacheViewsOnce extends ViewCacheCommand
{
    public function handle()
    {
        if (! config('nativephp-internal.running')) {
            return parent::handle();
        }

        $marker = config('view.compiled').DIRECTORY_SEPARATOR.'.views-signature';
        $current = $this->viewsSignature();

        if (is_file($marker) && file_get_contents($marker) === $current) {
            $this->components->info('Blade templates are already cached for these views.');

            return;
        }

        parent::handle();

        file_put_contents($marker, $current);
    }

    /**
     * Fingerprint of every Blade file view:cache would compile. Size and mtime
     * catch an update even when an older compiled copy looks newer than the
     * freshly installed source (Blade's own staleness check would miss that).
     */
    private function viewsSignature(): string
    {
        $files = $this->bladeFilesIn($this->paths()->all())
            ->map(fn ($file) => $file->getRealPath().'|'.$file->getSize().'|'.$file->getMTime())
            ->sort()
            ->implode("\n");

        return md5($this->laravel->version()."\n".$files);
    }
}
