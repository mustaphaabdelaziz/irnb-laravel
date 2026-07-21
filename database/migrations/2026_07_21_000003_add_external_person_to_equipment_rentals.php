<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Equipment can be handed to a team player OR to someone from outside the
     * club (a parent, a hired coach, a visiting team). The outsider has no
     * account, so their name and phone are captured as free text on the
     * rental, and the polymorphic rentable becomes nullable.
     */
    public function up(): void
    {
        Schema::table('equipment_rentals', function (Blueprint $table) {
            $table->string('external_name')->nullable()->after('rentable_id');
            $table->string('external_phone')->nullable()->after('external_name');
        });

        // The morph was created NOT NULL; an external rental has no rentable.
        Schema::table('equipment_rentals', function (Blueprint $table) {
            $table->string('rentable_type')->nullable()->change();
            $table->unsignedBigInteger('rentable_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('equipment_rentals', function (Blueprint $table) {
            $table->dropColumn(['external_name', 'external_phone']);
        });
    }
};
