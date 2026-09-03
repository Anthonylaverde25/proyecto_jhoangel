<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AnimalCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'code' => 'TERNERO',
                'name' => 'Ternero',
                'sex' => 'BOTH',
                'min_age_months' => 0,
                'max_age_months' => 12,
                'min_weight_kg' => 25.00,
                'max_weight_kg' => 240.00,
                'is_reproductive' => false,
                'description' => 'Crías machos y hembras desde el nacimiento hasta el destete / recría temprana (aprox. 12 meses).',
                'subcategories' => [],
            ],
            [
                'code' => 'VAQUILLONA',
                'name' => 'Vaquillona',
                'sex' => 'H',
                'min_age_months' => 12,
                'max_age_months' => 30,
                'min_weight_kg' => 240.00,
                'max_weight_kg' => 420.00,
                'is_reproductive' => true,
                'description' => 'Hembras jóvenes desde el año de edad hasta su primer servicio / parición.',
                'subcategories' => [
                    [
                        'code' => 'REPOSICION',
                        'name' => 'Vaquillona de Reposición',
                        'target_weight_min' => 270.00,
                        'target_weight_max' => 350.00,
                        'description' => 'Hembra seleccionada para ingresar al rodeo de cría como futura madre.',
                    ],
                    [
                        'code' => 'DESCARTE_FAENA',
                        'name' => 'Vaquillona Descarte / Faena',
                        'target_weight_min' => 300.00,
                        'target_weight_max' => 420.00,
                        'description' => 'Hembra no apta para reproducción, destinada a engorde y faena comercial.',
                    ],
                ],
            ],
            [
                'code' => 'VACA',
                'name' => 'Vaca Adulta',
                'sex' => 'H',
                'min_age_months' => 24,
                'max_age_months' => null,
                'min_weight_kg' => 380.00,
                'max_weight_kg' => 650.00,
                'is_reproductive' => true,
                'description' => 'Hembra adulta que ha tenido al menos un parto o superado la edad reproductiva adulta.',
                'subcategories' => [
                    [
                        'code' => 'RODEO_GENERAL',
                        'name' => 'Vaca de Rodeo General',
                        'target_weight_min' => 400.00,
                        'target_weight_max' => 550.00,
                        'description' => 'Vaca de cría comercial estándar del rodeo.',
                    ],
                    [
                        'code' => 'PLANTEL',
                        'name' => 'Vaca de Plantel / Cabaña',
                        'target_weight_min' => 450.00,
                        'target_weight_max' => 650.00,
                        'description' => 'Vaca de alta genética registrada para producción de reproductores y reemplazos puros.',
                    ],
                    [
                        'code' => 'DESCARTE_CUT',
                        'name' => 'Vaca CUT / Descarte',
                        'target_weight_min' => 380.00,
                        'target_weight_max' => 520.00,
                        'description' => 'Vaca de Cría Último Ternero o refugo por edad, desgaste dentario o problemas reproductivos.',
                    ],
                ],
            ],
            [
                'code' => 'NOVILLITO',
                'name' => 'Novillito',
                'sex' => 'M',
                'min_age_months' => 12,
                'max_age_months' => 20,
                'min_weight_kg' => 240.00,
                'max_weight_kg' => 380.00,
                'is_reproductive' => false,
                'description' => 'Macho castrado joven en etapa de recría / crecimiento.',
                'subcategories' => [],
            ],
            [
                'code' => 'NOVILLO',
                'name' => 'Novillo',
                'sex' => 'M',
                'min_age_months' => 20,
                'max_age_months' => null,
                'min_weight_kg' => 380.00,
                'max_weight_kg' => 550.00,
                'is_reproductive' => false,
                'description' => 'Macho castrado adulto en etapa de terminación / invernada a pasto o corral.',
                'subcategories' => [],
            ],
            [
                'code' => 'TORITO',
                'name' => 'Torito',
                'sex' => 'M',
                'min_age_months' => 12,
                'max_age_months' => 20,
                'min_weight_kg' => 260.00,
                'max_weight_kg' => 500.00,
                'is_reproductive' => true,
                'description' => 'Macho entero joven en recría o evaluación andrológica previa a ingresar a servicio.',
                'subcategories' => [],
            ],
            [
                'code' => 'TORO',
                'name' => 'Toro Reproductor',
                'sex' => 'M',
                'min_age_months' => 20,
                'max_age_months' => null,
                'min_weight_kg' => 600.00,
                'max_weight_kg' => 1050.00,
                'is_reproductive' => true,
                'description' => 'Macho entero adulto destinado a la reproducción en servicio estacionado o continuo.',
                'subcategories' => [],
            ],
        ];

        foreach ($categories as $catData) {
            $subcategories = $catData['subcategories'];
            unset($catData['subcategories']);

            $catData['created_at'] = now();
            $catData['updated_at'] = now();

            $existingCategory = DB::table('animal_categories')->where('code', $catData['code'])->first();

            if ($existingCategory) {
                DB::table('animal_categories')->where('id', $existingCategory->id)->update($catData);
                $categoryId = $existingCategory->id;
            } else {
                $categoryId = DB::table('animal_categories')->insertGetId($catData);
            }

            foreach ($subcategories as $subData) {
                $subData['category_id'] = $categoryId;
                $subData['created_at'] = now();
                $subData['updated_at'] = now();

                $existingSub = DB::table('animal_subcategories')
                    ->where('category_id', $categoryId)
                    ->where('code', $subData['code'])
                    ->first();

                if ($existingSub) {
                    DB::table('animal_subcategories')->where('id', $existingSub->id)->update($subData);
                } else {
                    DB::table('animal_subcategories')->insert($subData);
                }
            }
        }
    }
}
