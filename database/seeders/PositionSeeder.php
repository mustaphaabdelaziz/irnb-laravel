<?php

namespace Database\Seeders;

use App\Models\Position;
use Illuminate\Database\Seeder;

class PositionSeeder extends Seeder
{
    public function run(): void
    {
        $positions = [
            ['name' => 'حارس مرمى', 'abbreviation' => 'GK'],
            ['name' => 'مدافع', 'abbreviation' => 'DF'],
            ['name' => 'لاعب وسط', 'abbreviation' => 'MF'],
            ['name' => 'مهاجم', 'abbreviation' => 'FW'],
            ['name' => 'جناح', 'abbreviation' => 'WG'],
            ['name' => 'ظهير أيمن', 'abbreviation' => 'RB'],
            ['name' => 'ظهير أيسر', 'abbreviation' => 'LB'],
            ['name' => 'قلب دفاع', 'abbreviation' => 'CB'],
            ['name' => 'لاعب وسط دفاعي', 'abbreviation' => 'DM'],
            ['name' => 'لاعب وسط هجومي', 'abbreviation' => 'AM'],
            ['name' => 'مهاجم وسط', 'abbreviation' => 'CF'],
        ];

        foreach ($positions as $position) {
            Position::firstOrCreate(['abbreviation' => $position['abbreviation']], $position);
        }
    }
}
