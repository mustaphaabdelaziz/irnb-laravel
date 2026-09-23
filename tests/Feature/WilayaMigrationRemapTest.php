<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\CountryState;
use App\Models\CountryStateCommune;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WilayaMigrationRemapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Reproduces the messy state real installs are in: the ten southern
     * wilayas (external_id 49-58) filed under whatever external_id the old
     * seed JSON happened to give them, one of them (Touggourt) missing
     * entirely, and a commune already attached to the row that is really
     * Timimoun but is sitting at external_id 54.
     *
     * The migration must re-key these rows by name (not by their stale
     * external_id) so existing rows keep their identity - and anything
     * attached to them, like communes - while landing on the correct
     * official code.
     */
    #[Test]
    public function it_rekeys_legacy_southern_wilaya_rows_by_name_instead_of_a_stale_external_id(): void
    {
        $country = Country::query()->where('code', 'DZ')->firstOrFail();

        CountryState::query()
            ->where('country_id', $country->id)
            ->whereBetween('external_id', [49, 58])
            ->delete();

        $legacyRows = [
            49 => ['name' => "El M'ghair", 'ar_name' => 'المغير'],
            50 => ['name' => 'El Menia', 'ar_name' => 'المنيعة'],
            52 => ['name' => 'Bordj Baji Mokhtar', 'ar_name' => 'برج باجي مختار'],
            53 => ['name' => 'Béni Abbès', 'ar_name' => 'بني عباس'],
            54 => ['name' => 'Timimoun', 'ar_name' => 'تيميمون'],
            56 => ['name' => 'Djanet', 'ar_name' => 'جانت'],
            57 => ['name' => 'In Salah', 'ar_name' => 'عين صالح'],
            58 => ['name' => 'In Guezzam', 'ar_name' => 'عين قزام'],
            // 55 (Touggourt) intentionally missing, matching the dev DB.
        ];

        $timimounRowId = null;

        foreach ($legacyRows as $externalId => $attributes) {
            $state = CountryState::query()->create([
                'country_id' => $country->id,
                'external_id' => $externalId,
                'name' => $attributes['name'],
                'ar_name' => $attributes['ar_name'],
                'code' => null,
                'name_fr' => null,
                'name_ar' => null,
            ]);

            if ($attributes['name'] === 'Timimoun') {
                $timimounRowId = $state->id;
            }
        }

        $this->assertNotNull($timimounRowId);

        CountryStateCommune::query()->create([
            'country_state_id' => $timimounRowId,
            'name' => 'Timimoun-ville (test fixture)',
        ]);

        $migration = require database_path('migrations/2026_09_23_100003_official_wilayas.php');
        $migration->syncOfficialWilayas();

        $states = CountryState::query()->where('country_id', $country->id)->get();
        $this->assertCount(58, $states, 'every official wilaya must exist after the sync, including the missing Touggourt row');

        $official = require database_path('data/algeria_wilayas_official.php');

        foreach ($official as $code => $names) {
            $state = CountryState::query()->where('country_id', $country->id)->where('code', $code)->first();
            $this->assertNotNull($state, "wilaya {$code} missing after the remap+sync");
            $this->assertSame($names['fr'], $state->name_fr);
            $this->assertSame($names['ar'], $state->name_ar);
        }

        $timimoun = CountryState::query()->where('code', '49')->firstOrFail();
        $this->assertSame(
            $timimounRowId,
            $timimoun->id,
            'the legacy row keeps its identity (and its commune) across the rename instead of being replaced'
        );
        $this->assertSame(1, $timimoun->communes()->count());
        $this->assertSame('Timimoun-ville (test fixture)', $timimoun->communes()->first()->name);

        $touggourt = CountryState::query()->where('code', '55')->firstOrFail();
        $this->assertSame('Touggourt', $touggourt->name_fr, 'the missing row is inserted fresh with the correct name');
    }

    /**
     * Two existing rows that both resolve to the same official code (a
     * broken install that somehow duplicated one) must not collide on the
     * unique(country_id, external_id) constraint - the older row keeps the
     * code, the newer one is cleared so the sync inserts a clean row only
     * if nothing else claims that slot.
     */
    #[Test]
    public function a_duplicate_legacy_row_does_not_break_the_sync(): void
    {
        $country = Country::query()->where('code', 'DZ')->firstOrFail();

        CountryState::query()
            ->where('country_id', $country->id)
            ->where('external_id', 49)
            ->delete();

        $first = CountryState::query()->create([
            'country_id' => $country->id,
            'external_id' => 49,
            'name' => 'Timimoun',
            'ar_name' => 'تيميمون',
            'code' => null,
            'name_fr' => null,
            'name_ar' => null,
        ]);

        CountryState::query()->create([
            'country_id' => $country->id,
            'external_id' => null,
            'name' => 'Timimoun',
            'ar_name' => 'تيميمون',
            'code' => null,
            'name_fr' => null,
            'name_ar' => null,
        ]);

        $migration = require database_path('migrations/2026_09_23_100003_official_wilayas.php');
        $migration->syncOfficialWilayas();

        $timimoun = CountryState::query()->where('country_id', $country->id)->where('code', '49')->firstOrFail();
        $this->assertSame($first->id, $timimoun->id, 'the earlier row wins the code');
    }
}
