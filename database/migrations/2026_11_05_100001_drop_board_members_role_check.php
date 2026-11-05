<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * board_members.role began as an enum of five roles and later became a free
 * string (2026_07_03_000001). On PostgreSQL, change() left the enum's check
 * constraint behind, so a custom role is refused. Other databases have no such
 * constraint. IF EXISTS makes this safe on a server that already lost it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE board_members DROP CONSTRAINT IF EXISTS board_members_role_check');
        }
    }

    public function down(): void
    {
        // Nothing to undo: the dropped rule contradicted the free-text role column.
    }
};
