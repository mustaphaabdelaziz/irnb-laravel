<?php

namespace Tests\Feature\Help;

use Database\Seeders\HelpDemoImages;
use Database\Seeders\HelpDemoSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoImagesTest extends TestCase
{
    #[Test]
    public function no_image_is_written_outside_the_throwaway_storage(): void
    {
        $this->assertFalse(HelpDemoSeeder::onThrowawayStorage());
        $this->assertNull(HelpDemoImages::logo('#1d4ed8', '#facc15', 'shield'));
    }

    #[Test]
    #[DataProvider('shapes')]
    public function a_logo_is_a_png_in_the_throwaway_public_disk(string $shape): void
    {
        $root = sys_get_temp_dir().'/'.uniqid('help', true).'/help-demo-storage/app/public';
        File::ensureDirectoryExists($root);
        config(['filesystems.disks.public.root' => $root]);
        Storage::forgetDisk('public');

        try {
            $this->assertTrue(HelpDemoSeeder::onThrowawayStorage());
            $logo = HelpDemoImages::logo('#1d4ed8', '#facc15', $shape);

            $this->assertNotNull($logo);
            $this->assertStringStartsWith('opponents/', $logo['filename']);
            $this->assertFileExists($root.'/'.$logo['filename']);
            $this->assertSame(256, getimagesize($root.'/'.$logo['filename'])[0]);
        } finally {
            File::deleteDirectory(dirname($root, 3));
        }
    }

    /** @return array<string, array{string}> */
    public static function shapes(): array
    {
        return ['shield' => ['shield'], 'circle' => ['circle'], 'diamond' => ['diamond']];
    }
}
