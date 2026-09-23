<?php

namespace Tests\Feature;

use App\Models\CountryState;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WilayaDataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function all_fifty_eight_wilayas_exist_with_a_two_digit_code(): void
    {
        $states = CountryState::query()->orderBy('code')->get();

        $this->assertCount(58, $states);
        $this->assertSame('01', $states->first()->code);
        $this->assertSame('58', $states->last()->code);
    }

    #[Test]
    public function the_names_are_the_official_ones_in_both_languages(): void
    {
        $setif = CountryState::query()->where('code', '19')->firstOrFail();

        $this->assertSame('Sétif', $setif->name_fr);
        $this->assertSame('سطيف', $setif->name_ar);
        $this->assertSame('Sétif', $setif->name, 'the base name mirrors the French official name');
    }

    #[Test]
    public function the_old_misspellings_are_gone(): void
    {
        foreach (['Se9tif', 'Saefda', 'Ghardaefa', 'Tbessa'] as $typo) {
            $this->assertSame(0, CountryState::query()->where('name', $typo)->count(), $typo.' survived');
        }
    }

    #[Test]
    public function a_wilaya_reads_in_the_current_language(): void
    {
        $ghardaia = CountryState::query()->where('code', '47')->firstOrFail();

        App::setLocale('fr');
        $this->assertSame('Ghardaïa', $ghardaia->localized_name);

        App::setLocale('ar');
        $this->assertSame('غرداية', $ghardaia->localized_name);
    }

    #[Test]
    public function the_data_file_and_the_table_agree(): void
    {
        $official = require database_path('data/algeria_wilayas_official.php');

        $this->assertCount(58, $official);

        foreach ($official as $code => $names) {
            $state = CountryState::query()->where('code', $code)->first();
            $this->assertNotNull($state, "wilaya {$code} is missing");
            $this->assertSame($names['fr'], $state->name_fr);
            $this->assertSame($names['ar'], $state->name_ar);
        }
    }

    #[Test]
    public function seeding_after_migrating_does_not_duplicate_or_misspell_wilayas(): void
    {
        $this->seed(CountrySeeder::class);

        $states = CountryState::query()->get();

        $this->assertCount(58, $states, 'the seeder must not add rows the migration already created');

        $official = require database_path('data/algeria_wilayas_official.php');

        foreach ($official as $code => $names) {
            $state = CountryState::query()->where('code', $code)->first();
            $this->assertNotNull($state, "wilaya {$code} is missing after seeding");
            $this->assertSame($names['fr'], $state->name, "wilaya {$code} name was overwritten by the seeder");
            $this->assertSame($names['fr'], $state->name_fr);
            $this->assertSame($names['ar'], $state->name_ar);
        }

        $setif = CountryState::query()->where('code', '19')->firstOrFail();
        $this->assertGreaterThan(0, $setif->communes()->count(), 'the seeder should still attach communes to the migration-owned rows');
    }
}
