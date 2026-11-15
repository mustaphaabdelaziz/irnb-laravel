<?php

namespace Tests\Feature\Help;

use App\Models\Activity;
use App\Models\ActivityType;
use Database\Seeders\HelpDemoImages;
use Database\Seeders\HelpDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoImagesTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tmpRoot = null;

    protected function tearDown(): void
    {
        if ($this->tmpRoot !== null) {
            File::deleteDirectory($this->tmpRoot);
        }
        Storage::forgetDisk(['public', 'local']);
        parent::tearDown();
    }

    /** Points the public disk (and, unless told not to, the private one) at a throwaway folder. */
    private function pointDisks(bool $private = true): string
    {
        $this->tmpRoot = sys_get_temp_dir().'/'.uniqid('help', true);
        $base = $this->tmpRoot.'/help-demo-storage/app';
        File::ensureDirectoryExists($base.'/public');
        File::ensureDirectoryExists($base.'/private');
        config(['filesystems.disks.public.root' => $base.'/public']);
        if ($private) {
            config(['filesystems.disks.local.root' => $base.'/private']);
        }
        Storage::forgetDisk(['public', 'local']);

        return $base;
    }

    private function activity(): Activity
    {
        return Activity::create([
            'title' => 'نشاط تجريبي',
            'activity_type_id' => ActivityType::query()->firstOrFail()->id,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
        ]);
    }

    #[Test]
    public function no_image_is_written_outside_the_throwaway_storage(): void
    {
        $this->assertFalse(HelpDemoSeeder::onThrowawayStorage());
        $this->assertNull(HelpDemoImages::logo('#1d4ed8', '#facc15', 'shield'));
    }

    #[Test]
    public function photo_and_pdf_write_nothing_outside_the_throwaway_storage(): void
    {
        $a = $this->activity();

        $this->assertNull(HelpDemoImages::photo($a, 'beach'));
        $this->assertNull(HelpDemoImages::pdf($a, 'x.pdf'));
        $this->assertSame([], Storage::disk('public')->allFiles('activities/'.$a->id));
        $this->assertSame([], Storage::disk('local')->allFiles('activities/'.$a->id));
    }

    #[Test]
    public function photos_and_a_pdf_land_on_the_throwaway_disks(): void
    {
        $base = $this->pointDisks();
        $a = $this->activity();

        $this->assertTrue(HelpDemoSeeder::onThrowawayStorage());
        foreach (['field', 'beach', 'hall', 'pool'] as $scene) {
            $photo = HelpDemoImages::photo($a, $scene);
            $this->assertStringStartsWith('activities/'.$a->id.'/', $photo['path']);
            $this->assertStringStartsWith('activities/'.$a->id.'/thumbs/', $photo['thumb_path']);
            $this->assertFileExists($base.'/public/'.$photo['path']);
            $this->assertFileExists($base.'/public/'.$photo['thumb_path']);
            $this->assertSame(1600, getimagesize($base.'/public/'.$photo['path'])[0]);
        }

        $one = HelpDemoImages::photo($a, 'beach');
        $two = HelpDemoImages::photo($a, 'beach', 1);
        $this->assertNotSame(file_get_contents($base.'/public/'.$one['path']), file_get_contents($base.'/public/'.$two['path']));

        $pdf = HelpDemoImages::pdf($a, 'اتفاقية الشراكة.pdf');
        $this->assertSame('اتفاقية الشراكة.pdf', $pdf['original_name']);
        $this->assertStringStartsWith('%PDF-', file_get_contents($base.'/private/'.$pdf['path']));
    }

    #[Test]
    public function the_private_disk_must_move_too(): void
    {
        $this->pointDisks(private: false);

        $this->assertFalse(HelpDemoSeeder::onThrowawayStorage());
        $this->assertNull(HelpDemoImages::photo($this->activity(), 'field'));
    }

    #[Test]
    #[DataProvider('shapes')]
    public function a_logo_is_a_png_in_the_throwaway_public_disk(string $shape): void
    {
        $root = $this->pointDisks().'/public';

        $this->assertTrue(HelpDemoSeeder::onThrowawayStorage());
        $logo = HelpDemoImages::logo('#1d4ed8', '#facc15', $shape);

        $this->assertNotNull($logo);
        $this->assertStringStartsWith('opponents/', $logo['filename']);
        $this->assertFileExists($root.'/'.$logo['filename']);
        $this->assertSame(256, getimagesize($root.'/'.$logo['filename'])[0]);
    }

    /** @return array<string, array{string}> */
    public static function shapes(): array
    {
        return ['shield' => ['shield'], 'circle' => ['circle'], 'diamond' => ['diamond']];
    }
}
