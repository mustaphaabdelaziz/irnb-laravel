<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The unauthenticated /media/{path} route only ever reads the public disk.
 * `minutes/` and `receipts/` are private-disk folders now (Tasks 12 and 13):
 * a file left behind in public/minutes/ or public/receipts/ by an old upload
 * — one no row references any more, so no migration moved it — must never be
 * servable through this route, however the path is spelled.
 */
class PublicMediaRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    #[Test]
    public function an_orphan_file_in_the_minutes_folder_is_never_served(): void
    {
        Storage::disk('public')->put('minutes/orphan.pdf', 'ORPHAN-MINUTES');

        $this->get('/media/minutes/orphan.pdf')->assertNotFound();
    }

    #[Test]
    public function an_orphan_file_in_the_receipts_folder_is_never_served(): void
    {
        Storage::disk('public')->put('receipts/orphan.pdf', 'ORPHAN-RECEIPT');

        $this->get('/media/receipts/orphan.pdf')->assertNotFound();
    }

    /** @return array<string, array{0: string}> */
    public static function guardedPathVariants(): array
    {
        return [
            'plain minutes' => ['/media/minutes/x.pdf'],
            'plain receipts' => ['/media/receipts/x.pdf'],
            'uppercase first letter' => ['/media/Minutes/x.pdf'],
            'all caps' => ['/media/RECEIPTS/x.pdf'],
            'leading dot segment' => ['/media/./minutes/x.pdf'],
            'doubled slash' => ['/media//minutes/x.pdf'],
            'percent-encoded dot segment' => ['/media/%2e/receipts/x.pdf'],
        ];
    }

    #[Test]
    #[DataProvider('guardedPathVariants')]
    public function every_disguised_form_of_a_guarded_path_is_refused(string $url): void
    {
        Storage::disk('public')->put('minutes/x.pdf', 'MINUTES');
        Storage::disk('public')->put('receipts/x.pdf', 'RECEIPT');

        $this->get($url)->assertNotFound();
    }

    #[Test]
    public function other_public_files_are_still_served(): void
    {
        Storage::disk('public')->put('branding/logo.png', 'LOGO-BYTES');

        $this->get('/media/branding/logo.png')->assertOk();
    }

    #[Test]
    public function a_folder_that_merely_starts_with_the_same_letters_is_unaffected(): void
    {
        Storage::disk('public')->put('minutes-archive/x.pdf', 'NOT-A-MINUTES-FOLDER');

        $this->get('/media/minutes-archive/x.pdf')->assertOk();
    }
}
