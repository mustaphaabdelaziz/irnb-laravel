<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Player status was a free-form string with no constraint: imported rows
     * could carry anything, the value could never be translated, and the
     * allowed list lived in a hardcoded Vue <select>. It becomes a lookup
     * table like categories and positions.
     */
    public function up(): void
    {
        Schema::create('player_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('name_ar')->nullable();
            $table->string('name_fr')->nullable();
            $table->string('name_en')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // The six values previously hardcoded in PlayerForm.vue.
        $seed = [
            ['منخرط', 'Inscrit', 'Registered'],
            ['معتزل', 'Retraité', 'Retired'],
            ['متوقف', 'Suspendu', 'Paused'],
            ['غادر الفريق', 'A quitté le club', 'Left the club'],
            ['غير واضح', 'Indéterminé', 'Unclear'],
            ['معاقب', 'Sanctionné', 'Sanctioned'],
        ];

        $now = now();
        $rows = [];
        foreach ($seed as $i => [$ar, $fr, $en]) {
            $rows[] = [
                'name' => $ar,
                'name_ar' => $ar,
                'name_fr' => $fr,
                'name_en' => $en,
                'sort_order' => $i,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('player_statuses')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('player_statuses');
    }
};
