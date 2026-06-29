<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\CountryState;
use App\Models\CountryStateCommune;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/algeria_wilayas.json'), true);

        $country = Country::firstOrCreate(
            ['code' => 'DZ'],
            [
                'name' => 'Algeria',
                'flag' => '//upload.wikimedia.org/wikipedia/commons/7/77/Flag_of_Algeria.svg',
            ]
        );

        foreach ($data['states'] as $stateData) {
            $state = CountryState::firstOrCreate(
                ['country_id' => $country->id, 'external_id' => $stateData['id']],
                [
                    'name' => $stateData['name'],
                    'ar_name' => $stateData['ar_name'],
                    'longitude' => $stateData['longitude'],
                    'latitude' => $stateData['latitude'],
                ]
            );

            $communes = $data['communes'][(string) $stateData['id']] ?? [];
            foreach ($communes as $communeName) {
                CountryStateCommune::firstOrCreate(
                    ['country_state_id' => $state->id, 'name' => $communeName]
                );
            }
        }
    }
}
