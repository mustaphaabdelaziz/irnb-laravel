<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ships the built-in game formats (editable afterwards). A migration, not a
 * seeder, because the desktop build runs `migrate` on boot and never seeds.
 * Each preset links to the branch whose name (any language) matches its
 * sport; unmatched presets wait for an admin to pick a branch. Positions that
 * belong to no sport yet were all football ones, so they move to football.
 * Idempotent: a preset already present (by name) is skipped.
 */
return new class extends Migration
{
    private const SPORT_NAMES = [
        'football' => ['football', 'soccer', 'foot', 'كرة القدم'],
        'futsal' => ['futsal', 'football', 'soccer', 'foot', 'كرة القدم'],
        'handball' => ['handball', 'hand', 'كرة اليد'],
        'basketball' => ['basketball', 'basket', 'كرة السلة'],
        'volleyball' => ['volleyball', 'volley', 'كرة الطائرة'],
    ];

    public function up(): void
    {
        $branches = DB::table('branches')->get(['id', 'name', 'name_ar', 'name_fr', 'name_en']);
        $branchFor = function (string $sport) use ($branches): ?int {
            foreach (self::SPORT_NAMES[$sport] ?? [] as $wanted) {
                foreach ($branches as $branch) {
                    foreach (['name', 'name_en', 'name_fr', 'name_ar'] as $column) {
                        if (mb_strtolower(trim((string) $branch->{$column})) === $wanted) {
                            return (int) $branch->id;
                        }
                    }
                }
            }

            return null;
        };

        $now = now();
        foreach (self::presets() as $preset) {
            if (DB::table('game_formats')->where('is_preset', true)->where('name', $preset['name'])->exists()) {
                continue;
            }
            $formations = $preset['formations'];
            unset($preset['formations']);

            $id = DB::table('game_formats')->insertGetId([
                ...$preset,
                'branch_id' => $branchFor($preset['sport']),
                'sanction_types' => json_encode($preset['sanction_types']),
                'is_preset' => true,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($formations as $name => $slots) {
                DB::table('formations')->insert([
                    'game_format_id' => $id,
                    'name' => $name,
                    'slots' => json_encode(array_map(fn (array $s) => ['label' => $s[0], 'x' => $s[1], 'y' => $s[2]], $slots)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $football = $branchFor('football');
        if ($football !== null) {
            DB::table('positions')->whereNull('branch_id')->update(['branch_id' => $football]);
        }
    }

    public function down(): void
    {
        DB::table('game_formats')->where('is_preset', true)->delete();
    }

    /** @return list<array<string, mixed>> */
    private static function presets(): array
    {
        $cards = [
            ['key' => 'yellow', 'ends_play' => false, 'counts_as' => 'yellow'],
            ['key' => 'red', 'ends_play' => true, 'counts_as' => 'red'],
        ];
        // Slots: [label, x, y] — x left→right, y own goal (0) → opponent goal (100).
        $gk = ['GK', 50, 5];
        $back4 = [['LB', 15, 25], ['CB', 38, 22], ['CB', 62, 22], ['RB', 85, 25]];

        $base = fn (string $sport, string $name, string $ar, string $fr, int $field, int $bench, int $called, int $periods, int $minutes, string $label, string $scoring) => [
            'sport' => $sport, 'name' => $name, 'name_ar' => $ar, 'name_fr' => $fr, 'name_en' => $name,
            'players_on_field' => $field, 'max_bench' => $bench, 'max_called_up' => $called,
            'periods_count' => $periods, 'period_minutes' => $minutes, 'period_label' => $label, 'scoring' => $scoring,
            'sets_to_win' => null, 'has_extra_time' => false, 'has_shootout' => false,
        ];

        return [
            $base('football', 'Football 11', 'كرة القدم 11', 'Football à 11', 11, 9, 20, 2, 45, 'half', 'goals') + [
                'has_extra_time' => true, 'has_shootout' => true, 'sanction_types' => $cards,
                'formations' => [
                    '4-4-2' => [$gk, ...$back4, ['LM', 15, 55], ['CM', 38, 50], ['CM', 62, 50], ['RM', 85, 55], ['ST', 38, 82], ['ST', 62, 82]],
                    '4-3-3' => [$gk, ...$back4, ['CM', 30, 50], ['CM', 50, 45], ['CM', 70, 50], ['LW', 18, 80], ['ST', 50, 85], ['RW', 82, 80]],
                    '3-5-2' => [$gk, ['CB', 28, 22], ['CB', 50, 20], ['CB', 72, 22], ['LWB', 10, 50], ['CM', 32, 48], ['CM', 50, 55], ['CM', 68, 48], ['RWB', 90, 50], ['ST', 38, 82], ['ST', 62, 82]],
                    '4-2-3-1' => [$gk, ...$back4, ['DM', 38, 40], ['DM', 62, 40], ['LW', 18, 65], ['AM', 50, 65], ['RW', 82, 65], ['ST', 50, 85]],
                ],
            ],
            $base('football', 'Football 8', 'كرة القدم 8', 'Football à 8', 8, 6, 14, 2, 30, 'half', 'goals') + [
                'sanction_types' => $cards,
                'formations' => [
                    '3-3-1' => [$gk, ['DF', 25, 25], ['DF', 50, 22], ['DF', 75, 25], ['MF', 25, 55], ['MF', 50, 50], ['MF', 75, 55], ['ST', 50, 82]],
                    '2-3-2' => [$gk, ['DF', 35, 25], ['DF', 65, 25], ['MF', 20, 52], ['MF', 50, 50], ['MF', 80, 52], ['ST', 35, 80], ['ST', 65, 80]],
                ],
            ],
            $base('football', 'Football 5', 'كرة القدم 5', 'Football à 5', 5, 5, 10, 2, 20, 'half', 'goals') + [
                'sanction_types' => $cards,
                'formations' => [
                    '2-2' => [$gk, ['DF', 30, 30], ['DF', 70, 30], ['ST', 30, 70], ['ST', 70, 70]],
                    '1-2-1' => [$gk, ['DF', 50, 28], ['MF', 20, 52], ['MF', 80, 52], ['ST', 50, 80]],
                ],
            ],
            $base('futsal', 'Futsal', 'كرة القدم داخل القاعة', 'Futsal', 5, 9, 14, 2, 20, 'half', 'goals') + [
                'has_extra_time' => true, 'has_shootout' => true, 'sanction_types' => $cards,
                'formations' => [
                    '2-2' => [$gk, ['DF', 30, 30], ['DF', 70, 30], ['ST', 30, 70], ['ST', 70, 70]],
                    '1-2-1' => [$gk, ['DF', 50, 28], ['MF', 20, 52], ['MF', 80, 52], ['ST', 50, 80]],
                    '3-1' => [$gk, ['DF', 20, 35], ['DF', 50, 30], ['DF', 80, 35], ['ST', 50, 75]],
                ],
            ],
            $base('handball', 'Handball', 'كرة اليد', 'Handball', 7, 9, 16, 2, 30, 'half', 'goals') + [
                'has_extra_time' => true, 'has_shootout' => true,
                'sanction_types' => [
                    ['key' => 'yellow', 'ends_play' => false, 'counts_as' => 'yellow'],
                    ['key' => 'two_minutes', 'ends_play' => false, 'counts_as' => null],
                    ['key' => 'red', 'ends_play' => true, 'counts_as' => 'red'],
                ],
                'formations' => [
                    '3-3' => [$gk, ['LW', 8, 70], ['LB', 28, 55], ['CB', 50, 52], ['RB', 72, 55], ['RW', 92, 70], ['PV', 50, 82]],
                ],
            ],
            $base('basketball', 'Basketball', 'كرة السلة', 'Basketball', 5, 7, 12, 4, 10, 'quarter', 'points') + [
                'has_extra_time' => true,
                'sanction_types' => [
                    ['key' => 'foul', 'ends_play' => false, 'counts_as' => null],
                    ['key' => 'technical', 'ends_play' => false, 'counts_as' => null],
                    ['key' => 'unsportsmanlike', 'ends_play' => false, 'counts_as' => null],
                    ['key' => 'disqualifying', 'ends_play' => true, 'counts_as' => 'red'],
                ],
                'formations' => [
                    'standard' => [['PG', 50, 55], ['SG', 80, 65], ['SF', 20, 65], ['PF', 35, 85], ['C', 65, 85]],
                ],
            ],
            $base('basketball', 'Basketball 3x3', 'كرة السلة 3×3', 'Basket 3x3', 3, 1, 4, 1, 10, 'period', 'points') + [
                'has_extra_time' => true,
                'sanction_types' => [
                    ['key' => 'foul', 'ends_play' => false, 'counts_as' => null],
                    ['key' => 'technical', 'ends_play' => false, 'counts_as' => null],
                    ['key' => 'disqualifying', 'ends_play' => true, 'counts_as' => 'red'],
                ],
                'formations' => [
                    'standard' => [['G', 50, 55], ['F', 25, 80], ['F', 75, 80]],
                ],
            ],
            $base('volleyball', 'Volleyball', 'الكرة الطائرة', 'Volley-ball', 6, 8, 14, 5, 0, 'set', 'sets') + [
                'sets_to_win' => 3,
                'sanction_types' => [
                    ['key' => 'yellow', 'ends_play' => false, 'counts_as' => 'yellow'],
                    ['key' => 'red', 'ends_play' => false, 'counts_as' => null],
                    ['key' => 'expulsion', 'ends_play' => true, 'counts_as' => null],
                    ['key' => 'disqualification', 'ends_play' => true, 'counts_as' => 'red'],
                ],
                'formations' => [
                    '5-1' => [['S', 80, 20], ['OH', 80, 45], ['MB', 50, 45], ['OP', 20, 45], ['OH', 20, 20], ['MB', 50, 20]],
                ],
            ],
        ];
    }
};
