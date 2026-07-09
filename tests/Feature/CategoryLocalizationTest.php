<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CategoryLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'email_verified_at' => now()]);
    }

    #[Test]
    public function it_stores_per_language_names(): void
    {
        $this->actingAs($this->admin())
            ->post(route('categories.store'), [
                'name' => 'Senior',
                'name_ar' => 'أكابر',
                'name_fr' => 'Séniors',
                'name_en' => 'Senior',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('categories', ['name' => 'Senior', 'name_ar' => 'أكابر', 'name_fr' => 'Séniors']);
    }

    #[Test]
    public function localized_name_uses_the_current_locale_then_falls_back(): void
    {
        $cat = Category::create(['name' => 'Senior', 'name_fr' => 'Séniors']);

        App::setLocale('fr');
        $this->assertSame('Séniors', $cat->fresh()->localized_name);

        App::setLocale('ar'); // no Arabic name set → falls back to base name
        $this->assertSame('Senior', $cat->fresh()->localized_name);
    }

    #[Test]
    public function import_matches_a_category_by_any_locale_name(): void
    {
        $cat = Category::create(['name' => 'Senior', 'name_ar' => 'أكابر']);

        $header = array_fill(0, 19, 'header');
        $row = ['Ali', 'Brahimi', '', '', '', '2008-05-20', 'Male', '', '', 'Algiers', 'Algiers', 'أكابر', '', '', '', '5', '', '', (string) now()->year];

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $header);
        fputcsv($fh, $row);
        rewind($fh);
        $content = stream_get_contents($fh);
        fclose($fh);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $content);
        $file = new UploadedFile($path, 'players.csv', 'text/csv', null, true);

        $this->actingAs($this->admin())
            ->post(route('players.import.store'), ['file' => $file])
            ->assertRedirect();

        $this->assertSame($cat->id, Player::firstOrFail()->category_id);
    }
}
