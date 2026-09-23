<?php

namespace Tests\Unit\Providers;

use App\Providers\NativeAppServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The bundled static php.exe ships with no php.ini, so the desktop app runs
 * on PHP's compiled-in defaults: upload_max_filesize=2M, post_max_size=8M,
 * max_file_uploads=20 (confirmed by running the packaged php.exe directly).
 * P3 promises 10 MB per file and up to 10 files for player documents, board
 * minutes and transaction receipts, so phpIni() must raise all three —
 * NativePHP passes this array straight through as `-d` flags to `php -S`.
 */
class NativeAppServiceProviderTest extends TestCase
{
    #[Test]
    public function php_ini_raises_the_desktop_upload_limits_for_ten_megabyte_documents(): void
    {
        $ini = (new NativeAppServiceProvider($this->app))->phpIni();

        $this->assertSame('10M', $ini['upload_max_filesize']);
        $this->assertSame('110M', $ini['post_max_size']);
        $this->assertSame('20', $ini['max_file_uploads']);
    }

    #[Test]
    public function php_ini_keeps_the_existing_opcache_tuning(): void
    {
        $ini = (new NativeAppServiceProvider($this->app))->phpIni();

        $this->assertSame('1', $ini['opcache.enable']);
        $this->assertSame('1', $ini['opcache.enable_cli']);
        $this->assertSame('128', $ini['opcache.memory_consumption']);
        $this->assertSame('16', $ini['opcache.interned_strings_buffer']);
        $this->assertSame('20000', $ini['opcache.max_accelerated_files']);
        $this->assertSame('0', $ini['opcache.validate_timestamps']);
    }
}
