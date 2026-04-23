<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BreedSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $breeds = [
            'Angus',
            'Hereford',
            'Brangus',
            'Braford',
            'Holando',
            'Cruza',
            'Shorthorn',
            'Limousin',
        ];

        foreach ($breeds as $breed) {
            \App\Models\Breed::firstOrCreate(['name' => $breed]);
        }
    }
}
