<?php

use App\Support\LegacyFinanceCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment writers send only a slug ("subscription", "equipment", …), and
 * LegacyFinanceCategory finds the category by code, then by a name like the
 * slug. The old observer's twins ("Subscription", "Debt Payment", …) carry no
 * code, so renaming one in the manager would send its payments to "Other".
 *
 * For each slug → code pair, when no category of that type holds the code,
 * the same-named twin takes it. Idempotent; an existing code is never
 * overwritten; is_system is not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_categories')) {
            return;
        }

        foreach (LegacyFinanceCategory::CODES as $type => $codes) {
            foreach ($codes as $slug => $code) {
                $taken = DB::table('finance_categories')
                    ->where('type', $type)->where('code', $code)->exists();
                if ($taken) {
                    continue;
                }

                $twin = DB::table('finance_categories')
                    ->where('type', $type)
                    ->where('name', LegacyFinanceCategory::twinName($slug))
                    ->where(fn ($q) => $q->whereNull('code')->orWhere('code', ''))
                    ->orderBy('id')->value('id');

                if ($twin) {
                    DB::table('finance_categories')->where('id', $twin)->update(['code' => $code]);
                }
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty — the codes are harmless and a twin that held none cannot be told apart.
    }
};
