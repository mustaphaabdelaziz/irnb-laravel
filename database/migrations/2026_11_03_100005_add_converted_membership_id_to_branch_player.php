<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ID the bulk conversion wrote on a row. "Restore old IDs" only touches a
 * row that still holds exactly this ID, because legacy_membership_id is also
 * written when a registration is deleted or its year is corrected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('branch_player', 'converted_membership_id')) {
            Schema::table('branch_player', function (Blueprint $table) {
                $table->string('converted_membership_id', 24)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('branch_player', 'converted_membership_id')) {
            Schema::table('branch_player', fn (Blueprint $table) => $table->dropColumn('converted_membership_id'));
        }
    }
};
