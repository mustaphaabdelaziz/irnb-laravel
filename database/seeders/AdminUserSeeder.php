<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@irnb.local');

        if (User::where('email', $email)->exists()) {
            return;
        }

        User::create([
            'name' => env('ADMIN_FIRSTNAME', 'Admin').' '.env('ADMIN_LASTNAME', 'User'),
            'email' => $email,
            'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
            'firstname' => env('ADMIN_FIRSTNAME', 'Admin'),
            'lastname' => env('ADMIN_LASTNAME', 'User'),
            'gender' => env('ADMIN_GENDER', 'Male'),
            'phones' => env('ADMIN_PHONE') ? [env('ADMIN_PHONE')] : [],
            'is_user' => true,
            'is_active' => true,
            'approved' => true,
            'privileges' => ['superadmin', 'admin'],
            'preferred_lng' => 'ar',
            'email_verified_at' => now(),
        ]);
    }
}
