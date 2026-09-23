<?php

namespace Tests\Unit\Support;

use App\Support\Media;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaTest extends TestCase
{
    /** @var array<int, string> */
    private array $cleanupDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupDirs as $dir) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_normal_media_file_resolves_to_its_real_path(): void
    {
        $name = 'test-media-'.uniqid();
        $dir = storage_path('app/public/'.$name);
        $this->cleanupDirs[] = $dir;
        File::ensureDirectoryExists($dir);
        File::put($dir.'/logo.png', 'fake-bytes');

        $resolved = Media::localFile('/media/'.$name.'/logo.png');

        $this->assertNotNull($resolved);
        $this->assertFileExists($resolved);
        $this->assertStringEndsWith('logo.png', $resolved);
    }

    #[Test]
    public function a_path_with_dot_dot_segments_is_rejected(): void
    {
        // Even naming a real file that exists once the traversal is resolved
        // (storage/app/public/../../.env) must not come back.
        $this->assertNull(Media::localFile('/media/../../../.env'));
        $this->assertNull(Media::localFile('/storage/branding/../../../../.env'));
        $this->assertNull(Media::localFile('media/..%2f..%2f.env'));
    }

    #[Test]
    public function an_absolute_or_drive_path_is_rejected(): void
    {
        $this->assertNull(Media::localFile('C:\\Windows\\System32\\config'));
    }

    #[Test]
    public function a_missing_file_resolves_to_null(): void
    {
        $this->assertNull(Media::localFile('/media/does-not-exist-'.uniqid().'/nope.png'));
    }

    #[Test]
    public function a_null_or_empty_url_resolves_to_null(): void
    {
        $this->assertNull(Media::localFile(null));
        $this->assertNull(Media::localFile(''));
    }

    #[Test]
    public function it_falls_back_to_the_public_storage_copy_when_not_on_the_storage_disk(): void
    {
        $name = 'test-media-fallback-'.uniqid();
        $dir = public_path('storage/'.$name);
        $this->cleanupDirs[] = $dir;
        File::ensureDirectoryExists($dir);
        File::put($dir.'/logo.png', 'fake-bytes');

        // Guard: the file must NOT also exist on the primary disk, otherwise
        // this would pass without ever touching the fallback branch.
        $this->assertFileDoesNotExist(storage_path('app/public/'.$name.'/logo.png'));

        $resolved = Media::localFile('/media/'.$name.'/logo.png');

        $this->assertNotNull($resolved);
        $this->assertFileExists($resolved);
        $this->assertStringEndsWith('logo.png', $resolved);
    }
}
