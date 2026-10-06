<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A user's name is now computed from firstname + lastname. Users who only
 * ever had a typed name get it split: first word → firstname, the rest →
 * lastname (left empty for one-word names, filled in at the next edit).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where(fn ($q) => $q->whereNull('firstname')->orWhere('firstname', ''))
            ->orderBy('id')
            ->select(['id', 'name', 'lastname'])
            ->each(function ($row) {
                $parts = preg_split('/\s+/u', trim((string) $row->name), 2) ?: [];
                if (($parts[0] ?? '') === '') {
                    return;
                }

                DB::table('users')->where('id', $row->id)->update([
                    'firstname' => $parts[0],
                    'lastname' => filled($row->lastname) ? $row->lastname : ($parts[1] ?? null),
                ]);
            });
    }

    public function down(): void
    {
        // The split is lossless: name itself is untouched.
    }
};
