<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\CountryState;
use App\Models\CountryStateCommune;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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

        $second = CountryState::query()->create([
            'country_id' => $country->id,
            'external_id' => null,
            'name' => 'Timimoun',
            'ar_name' => 'تيميمون',
            'code' => null,
            'name_fr' => null,
            'name_ar' => null,
        ]);

        $totalBeforeSync = CountryState::query()->where('country_id', $country->id)->count();

        $migration = require database_path('migrations/2026_09_23_100003_official_wilayas.php');
        $migration->syncOfficialWilayas();

        $timimoun = CountryState::query()->where('country_id', $country->id)->where('code', '49')->firstOrFail();
        $this->assertSame($first->id, $timimoun->id, 'the earlier row wins the code');

        $this->assertNull($second->fresh()->external_id, 'the loser keeps no external_id');
        $this->assertNull($second->fresh()->code, 'the loser is never coded');

        $this->assertSame(
            $totalBeforeSync,
            CountryState::query()->where('country_id', $country->id)->count(),
            'the sync never deletes rows, including the stray loser'
        );
    }

    /**
     * A row with no external_id and a name that happens to match one of the
     * northern (1-48) wilayas is reachable in practice: ImportMongoJsonData
     * writes a null external_id when the source record has none. If the
     * correct row for that code already exists elsewhere in the table (i.e.
     * outside this legacy selection), the null-id row must never steal it -
     * that would violate unique(country_id, external_id) in the move phase
     * and, because SQLite/MySQL don't roll back the column-add DDL that ran
     * earlier in up(), leave the migration unrecorded but the columns
     * already added - so every later `migrate` fails with "duplicate column
     * name" and the app never boots again.
     */
    #[Test]
    public function a_null_external_id_row_cannot_steal_an_external_id_already_held_by_another_row(): void
    {
        $country = Country::query()->where('code', 'DZ')->firstOrFail();

        $originalAdrar = CountryState::query()->where('country_id', $country->id)->where('code', '01')->firstOrFail();

        $stray = CountryState::query()->create([
            'country_id' => $country->id,
            'external_id' => null,
            'name' => 'ADRAR',
            'ar_name' => null,
            'code' => null,
            'name_fr' => null,
            'name_ar' => null,
        ]);

        $migration = require database_path('migrations/2026_09_23_100003_official_wilayas.php');
        $migration->syncOfficialWilayas();

        $this->assertNull($stray->fresh()->external_id, 'the stray row cannot claim an external_id another row already holds');
        $this->assertNull($stray->fresh()->code);

        $originalAdrar->refresh();
        $this->assertEquals(1, $originalAdrar->external_id, 'the real Adrar row is untouched');
        $this->assertSame('01', $originalAdrar->code);
        $this->assertSame('Adrar', $originalAdrar->name_fr);

        $this->assertSame(
            58,
            CountryState::query()->where('country_id', $country->id)->whereNotNull('code')->count(),
            'exactly the 58 official rows are coded; the stray row is not one of them'
        );
    }

    /**
     * The flip side: a null-external_id row whose name matches an official
     * code that genuinely has no row yet (nothing else holds that
     * external_id) is a legitimate match and must be claimed, the same way
     * a southern-wilaya row would be.
     */
    #[Test]
    public function a_null_external_id_row_is_claimed_when_nothing_else_holds_that_code(): void
    {
        $country = Country::query()->where('code', 'DZ')->firstOrFail();

        CountryState::query()->where('country_id', $country->id)->where('code', '14')->delete();

        $stray = CountryState::query()->create([
            'country_id' => $country->id,
            'external_id' => null,
            'name' => 'Tiaret',
            'ar_name' => 'تيارت',
            'code' => null,
            'name_fr' => null,
            'name_ar' => null,
        ]);

        $migration = require database_path('migrations/2026_09_23_100003_official_wilayas.php');
        $migration->syncOfficialWilayas();

        $tiaret = CountryState::query()->where('country_id', $country->id)->where('code', '14')->firstOrFail();
        $this->assertSame($stray->id, $tiaret->id, 'the only candidate row is claimed since nothing else holds code 14');
        $this->assertEquals(14, $tiaret->external_id);
        $this->assertSame('Tiaret', $tiaret->name_fr);

        $this->assertCount(58, CountryState::query()->where('country_id', $country->id)->get());
    }

    /**
     * A row whose name matches nothing in the official list or the legacy
     * alias map (data corruption, or a truly foreign row) must not block
     * the sync: its external_id is cleared and a warning is logged so it
     * shows up at migrate time, and the official row for that code (if any)
     * is inserted fresh rather than stolen from the unmatched row.
     */
    #[Test]
    public function an_unmatched_legacy_name_is_cleared_and_warned_about(): void
    {
        $country = Country::query()->where('code', 'DZ')->firstOrFail();

        CountryState::query()->where('country_id', $country->id)->where('external_id', 50)->delete();

        $stray = CountryState::query()->create([
            'country_id' => $country->id,
            'external_id' => 50,
            'name' => 'Foo Bar',
            'ar_name' => null,
            'code' => null,
            'name_fr' => null,
            'name_ar' => null,
        ]);

        Log::spy();

        $migration = require database_path('migrations/2026_09_23_100003_official_wilayas.php');
        $migration->syncOfficialWilayas();

        Log::shouldHaveReceived('warning')->once();

        $this->assertNull($stray->fresh()->external_id);
        $this->assertNull($stray->fresh()->code);

        $bordjBadjiMokhtar = CountryState::query()->where('country_id', $country->id)->where('code', '50')->firstOrFail();
        $this->assertNotSame($stray->id, $bordjBadjiMokhtar->id, 'the official row is inserted fresh, not stolen from the unmatched row');
        $this->assertSame('Bordj Badji Mokhtar', $bordjBadjiMokhtar->name_fr);
    }

    /**
     * The desktop build runs `migrate` on every boot. If up() ever fails
     * partway (e.g. the collision this test file guards against) after the
     * column-add DDL already committed, the migration stays unrecorded and
     * every subsequent boot re-runs up() - which must not blow up on
     * "duplicate column name" when it tries to add columns that are already
     * there.
     */
    #[Test]
    public function running_up_again_after_the_columns_already_exist_does_not_throw(): void
    {
        $migration = require database_path('migrations/2026_09_23_100003_official_wilayas.php');

        $migration->up();

        $states = CountryState::query()->get();
        $this->assertCount(58, $states);

        $official = require database_path('data/algeria_wilayas_official.php');
        foreach ($official as $code => $names) {
            $state = CountryState::query()->where('code', $code)->first();
            $this->assertNotNull($state, "wilaya {$code} missing after re-running up()");
            $this->assertSame($names['fr'], $state->name_fr);
        }
    }
}
