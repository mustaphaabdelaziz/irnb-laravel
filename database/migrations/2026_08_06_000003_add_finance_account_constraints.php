<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Clean up duplicate treasuries - keep the oldest one per branch
        DB::statement('
            DELETE FROM finance_accounts
            WHERE is_treasury = 1
            AND id NOT IN (
                SELECT id FROM (
                    SELECT MIN(id) as id
                    FROM finance_accounts
                    WHERE is_treasury = 1
                    GROUP BY branch_id
                ) AS keep
            )
        ');

        // Note: Database-level constraints for "only one treasury per branch"
        // and "only one club bank account" are handled at the application level
        // via FinanceAccountController validation due to SQLite limitations
        // with conditional unique constraints.
        //
        // The validation rules in the controller enforce:
        // 1. Only one treasury per branch (checked before creating)
        // 2. Only one club-wide bank account (checked before creating)
    }

    public function down(): void
    {
        // Nothing to rollback
    }
};
