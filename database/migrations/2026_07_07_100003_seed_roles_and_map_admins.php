<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // One-time production data transition. Skipped under testing so the test
        // suite keeps an empty roles baseline (RoleSeeder is exercised directly).
        if (app()->environment('testing')) {
            return;
        }

        (new \Database\Seeders\RoleSeeder())->run();

        $adminRoleId = Role::where('key', 'administrator')->value('id');
        if ($adminRoleId === null) {
            return;
        }

        // Users with a legacy admin/superadmin privilege get the Administrator role.
        User::query()
            ->whereNull('role_id')
            ->get()
            ->each(function (User $user) use ($adminRoleId) {
                if (array_intersect(['admin', 'superadmin'], $user->privileges ?? [])) {
                    $user->update(['role_id' => $adminRoleId]);
                }
            });
    }

    public function down(): void
    {
        // Non-destructive: leave roles + assignments in place.
    }
};
