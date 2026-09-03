<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BatchType;
use App\Models\Company;
use Illuminate\Database\Seeder;

class BatchTypeSeeder extends Seeder
{
    public function run(): void
    {
        $companies = Company::all();

        foreach ($companies as $c) {

            // Operational
            BatchType::withoutGlobalScopes()->firstOrCreate(
                [
                    'company_id' => $c->id,
                    'code' => 'OPERATIONAL',
                ],
                [
                    'name' => 'Operational',
                    'description' => 'Lote operacional | Estandar',
                    'icon' => 'heroicons-outline:check-circle',
                    'color' => '#10b981',
                    'is_active' => true,
                ]
            );

            // Quarantine
            BatchType::withoutGlobalScopes()->firstOrCreate(
                [
                    'company_id' => $c->id,
                    'code' => 'QUARANTINE',
                ],
                [
                    'name' => 'Quarantine',
                    'description' => 'Lote en cuarentena sanitaria',
                    'icon' => 'heroicons-outline:shield-exclamation',
                    'color' => '#f59e0b',
                    'is_active' => true,
                ]
            );

            // Domestic Consumption
            BatchType::withoutGlobalScopes()->firstOrCreate(
                [
                    'company_id' => $c->id,
                    'code' => 'INTERNAL_CONSUMPTION',
                ],
                [
                    'name' => 'Internal Consumption',
                    'description' => 'Lote destinado a consumo interno',
                    'icon' => 'heroicons-outline:home',
                    'color' => '#3b82f6',
                    'is_active' => true,
                ]
            );

            // Internal Death
            BatchType::withoutGlobalScopes()->firstOrCreate(
                [
                    'company_id' => $c->id,
                    'code' => 'INTERNAL_DEATH',
                ],
                [
                    'name' => 'Internal Death',
                    'description' => 'Lote asociado a bajas o mortalidad interna',
                    'icon' => 'heroicons-outline:x-circle',
                    'color' => '#ef4444',
                    'is_active' => true,
                ]
            );

            // Reserve / Isolated Animals (System Batch Type)
            BatchType::withoutGlobalScopes()->firstOrCreate(
                [
                    'company_id' => $c->id,
                    'code' => 'RESERVE',
                ],
                [
                    'name' => 'Reserva / Apartados',
                    'description' => 'Lote interno del sistema para animales apartados y reserva genética',
                    'icon' => 'heroicons-outline:archive-box',
                    'color' => '#6366f1',
                    'is_active' => true,
                ]
            );

            // Service / Breeding Herd Batch
            BatchType::withoutGlobalScopes()->firstOrCreate(
                [
                    'company_id' => $c->id,
                    'code' => 'SERVICE',
                ],
                [
                    'name' => 'Servicio / Entore',
                    'description' => 'Lote reproductivo homogéneo para servicio natural o IATF',
                    'icon' => 'heroicons-outline:heart',
                    'color' => '#ec4899',
                    'is_active' => true,
                ]
            );

        }
    }
}