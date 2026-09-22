<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A status is found by a stable code, never by its display name: the name
     * is editable in Settings, so matching 'منخرط' breaks the moment an admin
     * renames it. Only the six built-in statuses get a code; ones an admin
     * adds later have none and need none.
     */
    public function up(): void
    {
        Schema::table('player_statuses', function (Blueprint $table) {
            $table->string('code', 32)->nullable()->unique()->after('id');
        });

        $codes = [
            'registered' => ['منخرط', 'Registered'],
            'retired' => ['معتزل', 'Retired'],
            'paused' => ['متوقف', 'Paused'],
            'left' => ['غادر الفريق', 'Left the club'],
            'unclear' => ['غير واضح', 'Unclear'],
            'sanctioned' => ['معاقب', 'Sanctioned'],
        ];

        foreach ($codes as $code => [$name, $english]) {
            // Match on either name: an install may already have renamed one.
            $id = DB::table('player_statuses')
                ->whereNull('code')
                ->where(fn ($q) => $q->where('name', $name)->orWhere('name_en', $english))
                ->orderBy('id')
                ->value('id');

            if ($id !== null) {
                DB::table('player_statuses')->where('id', $id)->update(['code' => $code]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('player_statuses', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
