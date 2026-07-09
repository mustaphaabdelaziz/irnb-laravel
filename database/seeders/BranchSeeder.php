<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            ['name' => 'Swimming', 'name_ar' => 'السباحة', 'name_fr' => 'Natation', 'name_en' => 'Swimming'],
            ['name' => 'Football', 'name_ar' => 'كرة القدم', 'name_fr' => 'Football', 'name_en' => 'Football'],
        ];

        foreach ($branches as $branch) {
            Branch::firstOrCreate(['name' => $branch['name']], $branch);
        }
    }
}
