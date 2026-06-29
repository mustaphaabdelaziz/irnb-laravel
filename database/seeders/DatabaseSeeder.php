<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            CountrySeeder::class,
            CategorySeeder::class,
            PositionSeeder::class,
            MemberJobSeeder::class,
            WebsiteConfigSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
