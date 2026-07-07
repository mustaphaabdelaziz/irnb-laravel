<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::updateOrCreate(['key' => 'superadmin'], [
            'name' => ['en' => 'Super Admin', 'fr' => 'Super Admin', 'ar' => 'مدير عام'],
            'permissions' => Role::allPermissions(),
            'is_system' => true,
        ]);

        Role::updateOrCreate(['key' => 'administrator'], [
            'name' => ['en' => 'Administrator', 'fr' => 'Administrateur', 'ar' => 'مدير'],
            'permissions' => Role::allPermissions(),
            'is_system' => true,
        ]);

        // Optional starter presets (editable/deletable).
        Role::updateOrCreate(['key' => 'accountant'], [
            'name' => ['en' => 'Accountant', 'fr' => 'Comptable', 'ar' => 'محاسب'],
            'permissions' => [
                'finance' => ['view', 'add', 'edit', 'delete'],
                'transactions' => ['view', 'add', 'edit'],
                'subscriptions' => ['view'],
                'reports' => ['view'],
            ],
            'is_system' => false,
        ]);

        Role::updateOrCreate(['key' => 'coach'], [
            'name' => ['en' => 'Coach', 'fr' => 'Entraîneur', 'ar' => 'مدرب'],
            'permissions' => [
                'players' => ['view', 'add', 'edit'],
                'equipment' => ['view'],
                'inventory' => ['view'],
            ],
            'is_system' => false,
        ]);
    }
}
