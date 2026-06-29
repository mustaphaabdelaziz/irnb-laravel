<?php

namespace Database\Seeders;

use App\Models\WebsiteConfig;
use Illuminate\Database\Seeder;

class WebsiteConfigSeeder extends Seeder
{
    public function run(): void
    {
        WebsiteConfig::singleton();
    }
}
