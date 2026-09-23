<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Legacy spellings from the old seed data (database/seeders/algeria_wilayas.json
     * before this migration), keyed by the official wilaya code they actually belong
     * to. The old JSON's `id` field did not track the official numbering for 7 of the
     * 10 southern wilayas (added 2019), so installed databases seeded from it have
     * those rows filed under the wrong external_id. This map lets the migration find
     * them by name instead, so the upsert loop below renames the correct row rather
     * than a random one that happens to share a stale external_id.
     *
     * @var array<string, string>
     */
    private const LEGACY_SOUTHERN_ALIASES = [
        "El M'ghair" => '57',
        'El Menia' => '58',
        'Ouled Djellal' => '51',
        'Bordj Baji Mokhtar' => '50',
        'Béni Abbès' => '52',
        'Timimoun' => '49',
        'Touggourt' => '55',
        'Djanet' => '56',
        'In Salah' => '53',
        'In Guezzam' => '54',
    ];

    /**
     * The 58 wilayas, with their official French and Arabic names.
     *
     * They arrive through a migration rather than a seeder because the desktop
     * build runs migrate on boot and never runs seeders — an installed copy
     * would otherwise keep the old misspelt names forever.
     */
    public function up(): void
    {
        // Guarded per-column: if a previous run of this migration added the
        // columns but then failed during the data sync below, SQLite/MySQL
        // won't have rolled back that DDL even though the migration itself
        // is unrecorded - the desktop build runs `migrate` on every boot, so
        // an unguarded add would fail every later run with "duplicate
        // column name" and the app would never boot again.
        Schema::table('country_states', function (Blueprint $table) {
            if (! Schema::hasColumn('country_states', 'code')) {
                $table->char('code', 2)->nullable()->after('external_id');
            }

            if (! Schema::hasColumn('country_states', 'name_fr')) {
                $table->string('name_fr')->nullable()->after('name');
            }

            if (! Schema::hasColumn('country_states', 'name_ar')) {
                $table->string('name_ar')->nullable()->after('name_fr');
            }
        });

        $this->syncOfficialWilayas();
    }

    /**
     * The actual data sync, split out from up() so a test can re-run it
     * against a hand-built "legacy" row set without trying to re-add the
     * columns up() already added.
     */
    public function syncOfficialWilayas(): void
    {
        // The whole sync is one transaction: if anything throws between the
        // temporary-offset move and the final one (or during the upsert
        // loop), every row write here rolls back instead of stranding
        // legacy rows at a temporary external_id forever. The column-add
        // DDL in up() is unaffected either way (see up()'s comment) - this
        // only protects the row data.
        DB::transaction(function () {
            $official = require database_path('data/algeria_wilayas_official.php');
            $now = now();

            $countryId = DB::table('countries')->where('code', 'DZ')->value('id');

            if ($countryId === null) {
                $countryId = DB::table('countries')->insertGetId([
                    'name' => 'Algeria',
                    'code' => 'DZ',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->rekeyLegacySouthernWilayas($countryId, $official);

            foreach ($official as $code => $names) {
                $number = (int) $code;

                $existing = DB::table('country_states')
                    ->where('country_id', $countryId)
                    ->where('external_id', $number)
                    ->first();

                $values = [
                    'code' => $code,
                    'name' => $names['fr'],
                    'name_fr' => $names['fr'],
                    'name_ar' => $names['ar'],
                    'ar_name' => $names['ar'],
                    'updated_at' => $now,
                ];

                if ($existing) {
                    DB::table('country_states')->where('id', $existing->id)->update($values);

                    continue;
                }

                DB::table('country_states')->insert($values + [
                    'country_id' => $countryId,
                    'external_id' => $number,
                    'created_at' => $now,
                ]);
            }
        });
    }

    /**
     * Some installed databases have the ten southern wilayas filed under the
     * wrong external_id (see the class doc-comment on LEGACY_SOUTHERN_ALIASES).
     * Matching the upsert loop above purely by external_id would silently
     * rename the wrong row on those installs, so re-key any existing row in
     * the southern range (or with no external_id at all) by its normalized
     * name first, moving it onto the correct official external_id.
     */
    private function rekeyLegacySouthernWilayas(int $countryId, array $official): void
    {
        $lookup = [];

        foreach ($official as $code => $names) {
            $lookup[$this->normalizeWilayaName($names['fr'])] = $code;
        }

        foreach (self::LEGACY_SOUTHERN_ALIASES as $legacyName => $code) {
            $lookup[$this->normalizeWilayaName($legacyName)] ??= $code;
        }

        $rows = DB::table('country_states')
            ->where('country_id', $countryId)
            ->where(function ($query) {
                $query->whereBetween('external_id', [49, 58])->orWhereNull('external_id');
            })
            ->orderBy('id')
            ->get();

        // A code already held by a row OUTSIDE this selection (a correctly
        // numbered wilaya we're not touching) must never be taken from it.
        // Reachable in practice: ImportMongoJsonData writes a null
        // external_id when the source record has none, and that row's name
        // can coincidentally match an already-correct 1-48 wilaya.
        $reservedExternalIds = DB::table('country_states')
            ->where('country_id', $countryId)
            ->whereNotIn('id', $rows->pluck('id'))
            ->whereNotNull('external_id')
            ->pluck('external_id')
            ->map(fn ($value) => (int) $value)
            ->all();

        $claimedBy = [];
        $moves = [];

        foreach ($rows as $row) {
            $code = $lookup[$this->normalizeWilayaName($row->name)] ?? null;

            if ($code === null) {
                if ($row->external_id !== null) {
                    DB::table('country_states')->where('id', $row->id)->update(['external_id' => null]);
                }

                logger()->warning(sprintf(
                    'official_wilayas migration: could not identify wilaya row id=%d name="%s" (external_id=%s); cleared external_id so the official row is inserted fresh instead of overwriting it.',
                    $row->id,
                    $row->name,
                    $row->external_id ?? 'null'
                ));

                continue;
            }

            if (in_array((int) $code, $reservedExternalIds, true)) {
                if ($row->external_id !== null) {
                    DB::table('country_states')->where('id', $row->id)->update(['external_id' => null]);
                }

                logger()->warning(sprintf(
                    'official_wilayas migration: row id=%d name="%s" matches code %s, but that external_id already belongs to another row outside this legacy selection; cleared external_id to avoid a duplicate.',
                    $row->id,
                    $row->name,
                    $code
                ));

                continue;
            }

            if (isset($claimedBy[$code])) {
                DB::table('country_states')->where('id', $row->id)->update(['external_id' => null]);

                logger()->warning(sprintf(
                    'official_wilayas migration: row id=%d name="%s" also matches code %s, already claimed by row id=%d; cleared external_id to avoid a duplicate.',
                    $row->id,
                    $row->name,
                    $code,
                    $claimedBy[$code]
                ));

                continue;
            }

            $claimedBy[$code] = $row->id;
            $moves[$row->id] = (int) $code;
        }

        if ($moves === []) {
            return;
        }

        // Two phases so we never collide with unique(country_id, external_id):
        // land every matched row on a temporary offset first, then on its
        // final official number. The offset is computed from the current
        // max external_id for this country (floor 1000) rather than a fixed
        // +1000, so it's clear of every external_id already in the table -
        // inside or outside this selection, official range or not - no
        // matter how messy the existing data is.
        $maxExternalId = (int) (DB::table('country_states')->where('country_id', $countryId)->max('external_id') ?? 0);
        $offset = max($maxExternalId + 1, 1000);

        foreach ($moves as $rowId => $targetCode) {
            DB::table('country_states')->where('id', $rowId)->update(['external_id' => $offset + $targetCode]);
        }

        foreach ($moves as $rowId => $targetCode) {
            DB::table('country_states')->where('id', $rowId)->update(['external_id' => $targetCode]);
        }
    }

    private function normalizeWilayaName(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii($value))) ?? '';
    }

    public function down(): void
    {
        Schema::table('country_states', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['code', 'name_fr', 'name_ar'],
                fn (string $column): bool => Schema::hasColumn('country_states', $column)
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
