<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FieldMapping;
use Illuminate\Database\Seeder;

class CaravanFieldMappingSeeder extends Seeder
{
    /**
     * Seed the default field mappings (synonyms) for the Caravan model.
     */
    public function run(): void
    {
        $mappings = [
            // teeth
            ['alias_name' => 'teeth', 'target_field' => 'teeth', 'target_model' => 'caravans'],
            ['alias_name' => 'dientes', 'target_field' => 'teeth', 'target_model' => 'caravans'],
            ['alias_name' => 'piezas_dentales', 'target_field' => 'teeth', 'target_model' => 'caravans'],
            ['alias_name' => 'dentadura', 'target_field' => 'teeth', 'target_model' => 'caravans'],
            ['alias_name' => 'edad_dental', 'target_field' => 'teeth', 'target_model' => 'caravans'],

            // identification
            ['alias_name' => 'identification', 'target_field' => 'identification', 'target_model' => 'caravans'],
            ['alias_name' => 'caravana', 'target_field' => 'identification', 'target_model' => 'caravans'],
            ['alias_name' => 'nro_caravana', 'target_field' => 'identification', 'target_model' => 'caravans'],
            ['alias_name' => 'numero', 'target_field' => 'identification', 'target_model' => 'caravans'],
            ['alias_name' => 'id_animal', 'target_field' => 'identification', 'target_model' => 'caravans'],
            ['alias_name' => 'nro', 'target_field' => 'identification', 'target_model' => 'caravans'],

            // category
            ['alias_name' => 'category', 'target_field' => 'category', 'target_model' => 'caravans'],
            ['alias_name' => 'categoria', 'target_field' => 'category', 'target_model' => 'caravans'],
            ['alias_name' => 'tipo', 'target_field' => 'category', 'target_model' => 'caravans'],
            ['alias_name' => 'clasificacion', 'target_field' => 'category', 'target_model' => 'caravans'],

            // entry_weight
            ['alias_name' => 'entry_weight', 'target_field' => 'entry_weight', 'target_model' => 'caravans'],
            ['alias_name' => 'peso_entrada', 'target_field' => 'entry_weight', 'target_model' => 'caravans'],
            ['alias_name' => 'peso_inicial', 'target_field' => 'entry_weight', 'target_model' => 'caravans'],
            ['alias_name' => 'kg_entrada', 'target_field' => 'entry_weight', 'target_model' => 'caravans'],
            ['alias_name' => 'peso_ingreso', 'target_field' => 'entry_weight', 'target_model' => 'caravans'],

            // exit_weight
            ['alias_name' => 'exit_weight', 'target_field' => 'exit_weight', 'target_model' => 'caravans'],
            ['alias_name' => 'peso_salida', 'target_field' => 'exit_weight', 'target_model' => 'caravans'],
            ['alias_name' => 'peso_final', 'target_field' => 'exit_weight', 'target_model' => 'caravans'],
            ['alias_name' => 'kg_salida', 'target_field' => 'exit_weight', 'target_model' => 'caravans'],
            ['alias_name' => 'peso_egreso', 'target_field' => 'exit_weight', 'target_model' => 'caravans'],

            // entry_weight (additional: peso_kg variants from real OCR)
            ['alias_name' => 'peso_kg', 'target_field' => 'entry_weight', 'target_model' => 'caravans'],
            ['alias_name' => 'peso', 'target_field' => 'entry_weight', 'target_model' => 'caravans'],
            ['alias_name' => 'kilos', 'target_field' => 'entry_weight', 'target_model' => 'caravans'],
            ['alias_name' => 'kg', 'target_field' => 'entry_weight', 'target_model' => 'caravans'],

            // breed
            ['alias_name' => 'breed', 'target_field' => 'breed', 'target_model' => 'caravans'],
            ['alias_name' => 'raza', 'target_field' => 'breed', 'target_model' => 'caravans'],
            ['alias_name' => 'Raza', 'target_field' => 'breed', 'target_model' => 'caravans'],
            ['alias_name' => 'raza_animal', 'target_field' => 'breed', 'target_model' => 'caravans'],
            ['alias_name' => 'Baza', 'target_field' => 'breed', 'target_model' => 'caravans'],

            // sex
            ['alias_name' => 'sex', 'target_field' => 'sex', 'target_model' => 'caravans'],
            ['alias_name' => 'sexo', 'target_field' => 'sex', 'target_model' => 'caravans'],
            ['alias_name' => 'genero', 'target_field' => 'sex', 'target_model' => 'caravans'],

            // entry_date
            ['alias_name' => 'entry_date', 'target_field' => 'entry_date', 'target_model' => 'caravans'],
            ['alias_name' => 'fecha_ingreso', 'target_field' => 'entry_date', 'target_model' => 'caravans'],
            ['alias_name' => 'fecha_entrada', 'target_field' => 'entry_date', 'target_model' => 'caravans'],
            ['alias_name' => 'ingreso', 'target_field' => 'entry_date', 'target_model' => 'caravans'],
        ];

        foreach ($mappings as $mapping) {
            FieldMapping::updateOrCreate(
                [
                    'alias_name' => $mapping['alias_name'],
                    'target_model' => $mapping['target_model'],
                ],
                [
                    'target_field' => $mapping['target_field'],
                ]
            );
        }
    }
}
