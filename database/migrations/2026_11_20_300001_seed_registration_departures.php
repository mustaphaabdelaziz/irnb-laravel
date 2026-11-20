<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every registration that is left today, with a known day, gets its departure as
 * a history line, so a later return cannot wipe it. A left row with no date gets
 * nothing: there is no day to record. A second run adds nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $left = DB::table('player_statuses')->where('code', 'left')->value('id');
        if ($left === null) {
            return;
        }
        $now = now();

        DB::table('branch_player')
            ->where('status_id', $left)
            ->whereNotNull('left_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($left, $now) {
                foreach ($rows as $row) {
                    $day = substr((string) $row->left_at, 0, 10);
                    $exists = DB::table('registration_status_changes')
                        ->where('branch_registration_id', $row->id)
                        ->where('to_status_id', $left)
                        ->whereDate('changed_on', $day)
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    DB::table('registration_status_changes')->insert([
                        'branch_registration_id' => $row->id,
                        'from_status_id' => null,
                        'to_status_id' => $left,
                        'changed_on' => $day,
                        'note' => null,
                        'user_id' => null,
                        'origin' => 'derived',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('registration_status_changes')->where('origin', 'derived')->whereNull('user_id')->delete();
    }
};
