<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The minutes file's real name, shown and served instead of the random stored one. */
    public function up(): void
    {
        Schema::table('board_meetings', function (Blueprint $table) {
            $table->string('attachment_name', 255)->nullable()->after('attachment_filename');
        });
    }

    public function down(): void
    {
        Schema::table('board_meetings', function (Blueprint $table) {
            $table->dropColumn('attachment_name');
        });
    }
};
