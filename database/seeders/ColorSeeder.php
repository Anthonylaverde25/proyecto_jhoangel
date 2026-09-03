<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Breed;
use App\Models\Color;
use Illuminate\Database\Seeder;

class ColorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $colors = [
            ['name' => 'Negro', 'code' => 'NE'],
            ['name' => 'Colorado', 'code' => 'CO'],
            ['name' => 'Pampa', 'code' => 'PA'],
            ['name' => 'Overo Negro', 'code' => 'ON'],
            ['name' => 'Overo Colorado', 'code' => 'OC'],
            ['name' => 'Rosillo', 'code' => 'RO'],
            ['name' => 'Bayo', 'code' => 'BA'],
            ['name' => 'Blanco', 'code' => 'BL'],
            ['name' => 'Barcino', 'code' => 'BC'],
        ];

        $colorModels = [];
        foreach ($colors as $item) {
            $colorModels[$item['name']] = Color::firstOrCreate(
                ['name' => $item['name']],
                ['code' => $item['code']]
            );
        }

        // Map typical breed coat varieties into breed_color pivot table
        $breedColorMap = [
            'Angus' => ['Negro', 'Colorado'],
            'Hereford' => ['Pampa'],
            'Brangus' => ['Negro', 'Colorado'],
            'Braford' => ['Pampa', 'Colorado'],
            'Holando' => ['Overo Negro', 'Overo Colorado'],
            'Shorthorn' => ['Colorado', 'Blanco', 'Rosillo'],
            'Limousin' => ['Colorado', 'Negro'],
            'Cruza' => ['Negro', 'Colorado', 'Pampa', 'Overo Negro', 'Overo Colorado', 'Rosillo', 'Bayo', 'Blanco', 'Barcino'],
        ];

        foreach ($breedColorMap as $breedName => $colorNames) {
            $breed = Breed::where('name', $breedName)->first();
            if (!$breed) {
                continue;
            }

            $colorIds = [];
            foreach ($colorNames as $colorName) {
                if (isset($colorModels[$colorName])) {
                    $colorIds[] = $colorModels[$colorName]->id;
                }
            }

            $breed->colors()->syncWithoutDetaching($colorIds);
        }
    }
}
