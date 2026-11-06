<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Model events stay on: BranchObserver adopts the categories, sets the main branch and
    // provisions the coffre, and User gets its username, exactly as creating them in the app does.
    public function run(): void
    {
        $this->call([
            CountrySeeder::class,
            CategorySeeder::class,
            BranchSeeder::class,
            PositionSeeder::class,
            MemberJobSeeder::class,
            WebsiteConfigSeeder::class,
            RoleSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
