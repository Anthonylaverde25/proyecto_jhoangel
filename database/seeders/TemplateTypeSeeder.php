<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\TemplateType;
use App\Models\WorkTemplate;
use Illuminate\Database\Seeder;

class TemplateTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $companies = Company::all();

        foreach ($companies as $company) {
            // 1. Ingreso Ganadero
            $entryType = TemplateType::create([
                'company_id' => $company->id,
                'name' => 'Ingreso Ganadero',
                'code' => 'ENTRY',
                'icon' => 'heroicons-outline:arrow-down-tray',
                'color' => '#4CAF50',
                'description' => 'Plantillas para el alta de animales en el sistema.'
            ]);

            // 2. Control de Peso
            $weightType = TemplateType::create([
                'company_id' => $company->id,
                'name' => 'Control de Peso',
                'code' => 'WEIGHT',
                'icon' => 'heroicons-outline:scale',
                'color' => '#2196F3',
                'description' => 'Seguimiento de pesaje y biomasa.'
            ]);

            // 3. Cambio de Actividad
            $activityType = TemplateType::create([
                'company_id' => $company->id,
                'name' => 'Cambio de Actividad',
                'code' => 'ACTIVITY',
                'icon' => 'heroicons-outline:arrows-right-left',
                'color' => '#FF9800',
                'description' => 'Registro de transferencias entre etapas productivas.'
            ]);

            // 4. Sanidad (Extra para mantener consistencia con lo que vimos en la UI)
            $healthType = TemplateType::create([
                'company_id' => $company->id,
                'name' => 'Sanidad',
                'code' => 'HEALTH',
                'icon' => 'heroicons-outline:shield-check',
                'color' => '#10b981',
                'description' => 'Control de vacunación y tratamientos.'
            ]);

            // Seed some templates for each type
            WorkTemplate::create([
                'company_id' => $company->id,
                'type_id' => $entryType->id,
                'title' => 'Ingreso de Compra Directa',
                'description' => 'Registro básico de ingreso con datos de proveedor y pesaje inicial.',
                'status' => 'active'
            ]);

            WorkTemplate::create([
                'company_id' => $company->id,
                'type_id' => $weightType->id,
                'title' => 'Control Mensual de Lotes',
                'description' => 'Planilla para el pesaje de rutina mensual de tropas en recría.',
                'status' => 'active'
            ]);

            WorkTemplate::create([
                'company_id' => $company->id,
                'type_id' => $activityType->id,
                'title' => 'Transferencia a Invernada',
                'description' => 'Movimiento de lotes que finalizan la recría y pasan a terminación.',
                'status' => 'active'
            ]);
        }
    }
}
