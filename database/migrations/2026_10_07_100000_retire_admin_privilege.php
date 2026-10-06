<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Access comes from roles only. The legacy `admin` privilege granted
 * everything whatever the role, so a role change looked ignored. It is
 * removed: users who also have a role keep that role; users with no role
 * get the Administrator system role so nobody loses access. `superadmin`
 * (the owner account) is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $administratorId = DB::table('roles')->where('key', 'administrator')->value('id');

        DB::table('users')->whereNotNull('privileges')->orderBy('id')->select(['id', 'privileges', 'role_id'])
            ->each(function ($row) use ($administratorId) {
                $privileges = json_decode((string) $row->privileges, true);
                if (! is_array($privileges) || ! in_array('admin', $privileges, true)) {
                    return;
                }

                $kept = array_values(array_diff($privileges, ['admin']));
                $update = ['privileges' => json_encode($kept === [] ? ['user'] : $kept)];

                if ($row->role_id === null && $administratorId !== null) {
                    $update['role_id'] = $administratorId;
                }

                DB::table('users')->where('id', $row->id)->update($update);
            });
    }

    public function down(): void
    {
        // Not restored: roles carry the same access.
    }
};
