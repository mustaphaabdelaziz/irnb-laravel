<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_rentals', function (Blueprint $table) {
            // 'rental' is a temporary loan with a due date; 'assignment' is
            // equipment given to a person to work with, open-ended and never
            // overdue.
            $table->string('type')->default('rental')->after('rentable_id')->index();

            $table->unsignedInteger('quantity')->default(1)->after('type');
            $table->unsignedInteger('returned_quantity')->default(0)->after('quantity');

            // Its own column so returning no longer destroys the checkout note.
            $table->text('return_notes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('equipment_rentals', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'quantity', 'returned_quantity', 'return_notes']);
        });
    }
};
