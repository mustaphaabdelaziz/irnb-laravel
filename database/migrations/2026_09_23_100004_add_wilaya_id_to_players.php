<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy spellings from the old seed data that don't normalise to any
     * official name/code (typos, or southern wilayas the old JSON numbered
     * differently — see 2026_09_23_100003_official_wilayas.php), keyed by the
     * official wilaya code they actually belong to.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'se9tif' => '19',
        'saefda' => '20',
        'ghardaefa' => '47',
        'tbessa' => '12',
        "El M'ghair" => '57',
        'El Menia' => '58',
        'Bordj Baji Mokhtar' => '50',
    ];

    /**
     * Players point at a wilaya row instead of holding its name as text.
     *
     * The old `state` column stays for one release: a value that cannot be
     * matched (a typo, a commune written in the wilaya box) is left there
     * rather than thrown away, so nothing is lost silently.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->foreignId('wilaya_id')->nullable()->after('state')
                ->constrained('country_states')->nullOnDelete();
        });

        $this->backfillWilayaId();
    }

    /**
     * The actual backfill, split out from up() so a test can re-run it
     * against a hand-built "legacy" player/state row set without trying to
     * re-add the wilaya_id column up() already added.
     */
    public function backfillWilayaId(): void
    {
        $byKey = [];

        foreach (DB::table('country_states')->whereNotNull('code')->get() as $state) {
            foreach ([$state->code, $state->name, $state->name_fr, $state->name_ar, $state->ar_name] as $value) {
                $key = self::normalise((string) $value);
                if ($key !== '') {
                    $byKey[$key] = $state->id;
                }
            }
        }

        // The spellings that reached this database before the official list did.
        foreach (self::ALIASES as $typo => $code) {
            $id = DB::table('country_states')->where('code', $code)->value('id');
            if ($id !== null) {
                $byKey[self::normalise($typo)] = $id;
            }
        }

        $unmatched = [];

        foreach (DB::table('players')->whereNotNull('state')->select('id', 'state')->get() as $player) {
            $id = $byKey[self::normalise((string) $player->state)] ?? null;

            if ($id !== null) {
                DB::table('players')->where('id', $player->id)->update(['wilaya_id' => $id]);
            } elseif (trim((string) $player->state) !== '') {
                $unmatched[trim((string) $player->state)] = true;
            }
        }

        // The owner needs to know which player.state values could not be
        // matched to a wilaya, so they can be fixed by hand — the migration
        // deliberately leaves `state` untouched for these rows rather than
        // guessing.
        if ($unmatched !== []) {
            $values = array_values(array_keys($unmatched));

            logger()->warning('Wilaya backfill: unmatched player.state values', [
                'values' => $values,
                'count' => count($values),
            ]);

            if (app()->runningInConsole()) {
                fwrite(STDERR, 'Wilaya backfill: '.count($values)." player.state value(s) could not be matched to a wilaya:\n");
                foreach ($values as $value) {
                    fwrite(STDERR, '  - '.$value."\n");
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wilaya_id');
        });
    }

    /**
     * Lower-cased, accent-free, letters/digits/Arabic only — so "GHARDAIA"
     * meets "Ghardaïa" and "SÉTIF" meets "Sétif".
     *
     * Kept as a private copy rather than calling App\Support\WilayaMatcher:
     * migrations must not depend on app classes that can change (or be
     * deleted/renamed) later — a migration must keep working exactly as
     * written, forever. The duplication with WilayaMatcher::normalise() is
     * deliberate.
     */
    private static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));

        $accents = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];

        $value = strtr($value, $accents);

        return (string) preg_replace('/[^a-z0-9\p{Arabic}]/u', '', $value);
    }
};
