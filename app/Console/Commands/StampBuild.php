<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Writes a fresh build id into the app before a desktop build (it runs as a
 * NativePHP prebuild step), so the packaged app can tell one build from the
 * next — the OPcache file cache is keyed on it.
 */
class StampBuild extends Command
{
    protected $signature = 'app:stamp-build';

    protected $description = 'Write a fresh build id for the desktop build';

    public function handle(): int
    {
        $id = now()->format('YmdHis').'-'.bin2hex(random_bytes(4));

        file_put_contents(base_path('build-id'), $id);

        $this->components->info("Build id {$id}");

        return self::SUCCESS;
    }
}
