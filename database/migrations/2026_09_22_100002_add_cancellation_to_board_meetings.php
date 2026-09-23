<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meetings are never deleted, only cancelled — and a cancellation records
     * who, when and why. The status enum already has 'cancelled' and is not
     * altered (an enum change rebuilds the table on SQLite).
     */
    public function up(): void
    {
        Schema::table('board_meetings', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('status');
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('cancelled_by_user_id');
        });

        // Meetings set to cancelled through the old status dropdown have no stamp.
        DB::table('board_meetings')
            ->where('status', 'cancelled')
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('board_meetings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
