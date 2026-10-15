<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 3: the federation licence on the player, and document types that must be in order to play matches. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('licence_number', 50)->nullable();
        });
        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('required_for_matches')->default(false)->after('is_required');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('required_for_matches'));
        Schema::table('players', fn (Blueprint $table) => $table->dropColumn('licence_number'));
    }
};
