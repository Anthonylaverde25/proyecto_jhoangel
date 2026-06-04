<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\WorkTemplate;
use Illuminate\Database\Seeder;

class WorkTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $companies = Company::all();

        foreach ($companies as $company) {
            // Seed some templates for each company
            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'ING-01'],
                [
                    'category' => 'ENTRY',
                    'title' => 'Ingreso de Compra Directa',
                    'description' => 'Registro básico de ingreso con datos de proveedor y pesaje inicial.',
                    'status' => 'active'
                ]
            );

            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'OP-01'],
                [
                    'category' => 'WEIGHT',
                    'title' => 'Control Mensual de Lotes',
                    'description' => 'Planilla para el pesaje de rutina mensual de tropas en recría.',
                    'status' => 'active'
                ]
            );

            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'OP-02'],
                [
                    'category' => 'ACTIVITY',
                    'title' => 'Transferencia a Invernada',
                    'description' => 'Movimiento de lotes que finalizan la recría y pasan a terminación.',
                    'status' => 'active'
                ]
            );

            WorkTemplate::updateOrCreate(
                ['company_id' => $company->id, 'code' => 'REP-01'],
                [
                    'category' => 'REPRODUCTIVE',
                    'title' => 'Planilla de Tacto y Ecografía',
                    'description' => 'Registro de diagnóstico de gestación, tacto rectal y ecografía.',
                    'status' => 'active',
                    'schema_definition' => [
                        [
                            'name' => 'caravana',
                            'label' => 'Caravana',
                            'type' => 'string',
                            'required' => true,
                            'validation' => [
                                'rules' => ['required', 'alpha_num']
                            ]
                        ],
                        [
                            'name' => 'category',
                            'label' => 'Categoría',
                            'type' => 'string',
                            'required' => true,
                            'validation' => [
                                'rules' => ['required']
                            ]
                        ],
                        [
                            'name' => 'diagnosis',
                            'label' => 'Diagnóstico',
                            'type' => 'select',
                            'required' => true,
                            'options' => [
                                ['value' => 'PREGNANT', 'label' => 'Preñada'],
                                ['value' => 'EMPTY', 'label' => 'Vacía']
                            ],
                            'validation' => [
                                'rules' => ['required']
                            ]
                        ],
                        [
                            'name' => 'gestational_stage',
                            'label' => 'Estadio Estimado',
                            'type' => 'select',
                            'required' => false,
                            'options' => [
                                ['value' => 'CABEZA', 'label' => 'Cabeza'],
                                ['value' => 'CUERPO', 'label' => 'Cuerpo'],
                                ['value' => 'COLA', 'label' => 'Cola']
                            ],
                            'validation' => [
                                'rules' => ['nullable']
                            ]
                        ],
                        [
                            'name' => 'observations',
                            'label' => 'Observaciones',
                            'type' => 'text',
                            'required' => false
                        ]
                    ]
                ]
            );
        }
    }
}
