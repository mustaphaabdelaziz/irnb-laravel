<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Senior', 'description' => 'For senior members of the club'],
            ['name' => 'Junior', 'description' => 'For junior members of the club'],
            ['name' => 'Cadet', 'description' => 'For cadet members of the club'],
            ['name' => 'Minime', 'description' => 'For minime members of the club'],
            ['name' => 'Ecole', 'description' => 'For school-level members of the club'],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(['name' => $category['name']], $category);
        }
    }
}
