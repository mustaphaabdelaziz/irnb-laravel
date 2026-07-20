<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->foreignId('status_id')->nullable()->after('status_value')
                ->constrained('player_statuses')->nullOnDelete();
        });

        $known = DB::table('player_statuses')->pluck('id', 'name');

        $distinct = DB::table('players')
            ->whereNotNull('status_value')
            ->where('status_value', '!=', '')
            ->distinct()
            ->pluck('status_value');

        $now = now();

        foreach ($distinct as $value) {
            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }

            // An unrecognised value is imported rather than discarded, marked
            // inactive so it still appears in the statistics and can be
            // cleaned up from Settings, but is not offered when editing.
            if (! isset($known[$trimmed])) {
                $known[$trimmed] = DB::table('player_statuses')->insertGetId([
                    'name' => $trimmed,
                    'name_ar' => $trimmed,
                    'sort_order' => 99,
                    'is_active' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('players')->where('status_value', $value)
                ->update(['status_id' => $known[$trimmed]]);
        }

        // status_value is deliberately kept for one release as the audit trail
        // for this backfill. Dropping it is follow-up work.
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_id');
        });
    }
};
