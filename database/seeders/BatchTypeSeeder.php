<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\BatchType;
use App\Models\Company;
use App\Models\CompanyBatchType;
use Illuminate\Database\Seeder;

/**
 * Canonical batch type catalogue (twelve types in four families).
 *
 * `activity_id` is a CATALOGUE constraint, not an instance one: a type with an
 * activity is only offered inside that activity, and a type with NULL is
 * cross-cutting and offered everywhere. The management system (pen vs. pasture)
 * is NOT part of this catalogue: it lives in `batches.is_confined`.
 */
class BatchTypeSeeder extends Seeder
{
    public function run(): void
    {
        $activityIds = Activity::whereIn('code', ['CRIA', 'RECRIA', 'INTERNAL'])
            ->pluck('id', 'code')
            ->all();

        $criaId = $activityIds['CRIA'] ?? null;
        $recriaId = $activityIds['RECRIA'] ?? null;
        $internalId = $activityIds['INTERNAL'] ?? null;

        $batchTypes = [
            // --- General (cross-cutting) ---
            [
                'code' => 'OPERATIONAL',
                'name' => 'Operational',
                'description' => 'Lote operacional | Estandar',
                'icon' => 'heroicons-outline:check-circle',
                'color' => '#10b981',
                'activity_id' => null,
                'is_active' => true,
            ],
            [
                'code' => 'RESERVE',
                'name' => 'Reserva / Apartados',
                'description' => 'Lote interno del sistema para animales apartados y reserva genética',
                'icon' => 'heroicons-outline:archive-box',
                'color' => '#6366f1',
                'activity_id' => null,
                'is_active' => true,
                // Created only by GetOrCreateReserveBatchUseCase, never picked by hand.
                'is_selectable' => false,
            ],

            // --- Cría ---
            [
                'code' => 'SERVICE',
                'name' => 'Servicio / Entore',
                'description' => 'Lote reproductivo homogéneo para servicio natural o IATF',
                'icon' => 'heroicons-outline:heart',
                'color' => '#ec4899',
                'activity_id' => $criaId,
                'is_active' => true,
            ],
            [
                'code' => 'WEANING',
                'name' => 'Lote de Destete',
                'description' => 'Lote transitorio de desmadre, acostumbramiento o concentración para comercialización',
                'icon' => 'heroicons-outline:clock',
                'color' => '#8b5cf6',
                'activity_id' => $criaId,
                'is_active' => true,
            ],

            // --- Recría ---
            [
                'code' => 'GROWING_HEIFERS',
                'name' => 'Vaquillonas de Recría',
                'description' => 'Hembras en crecimiento destinadas a engorde o venta',
                'icon' => 'heroicons-outline:arrow-trending-up',
                'color' => '#06b6d4',
                'activity_id' => $recriaId,
                'is_active' => true,
            ],
            [
                'code' => 'GROWING_STEERS',
                'name' => 'Novillitos de Recría',
                'description' => 'Machos castrados en desarrollo esquelético, previo a terminación',
                'icon' => 'heroicons-outline:chart-bar',
                'color' => '#3b82f6',
                'activity_id' => $recriaId,
                'is_active' => true,
            ],
            [
                'code' => 'GROWING_REPLACEMENT_FEMALES',
                'name' => 'Vientres de Reposición',
                'description' => 'Hembras retenidas para reemplazar los vientres de descarte del rodeo',
                'icon' => 'heroicons-outline:sparkles',
                'color' => '#ec4899',
                'activity_id' => $recriaId,
                'is_active' => true,
            ],
            [
                'code' => 'GROWING_REPLACEMENT_BULLS',
                'name' => 'Toritos de Reposición',
                'description' => 'Machos enteros de alto mérito genético destinados a futuros toros padres',
                'icon' => 'heroicons-outline:academic-cap',
                'color' => '#ea580c',
                'activity_id' => $recriaId,
                'is_active' => true,
            ],
            [
                'code' => 'GROWING_MIXED',
                'name' => 'Recría General',
                'description' => 'Tropa mixta post-destete todavía sin clasificar por sexo y destino',
                'icon' => 'heroicons-outline:user-group',
                'color' => '#64748b',
                'activity_id' => $recriaId,
                'is_active' => true,
            ],

            // --- Internal ---
            [
                'code' => 'QUARANTINE',
                'name' => 'Quarantine',
                'description' => 'Lote en cuarentena sanitaria',
                'icon' => 'heroicons-outline:shield-exclamation',
                'color' => '#f59e0b',
                'activity_id' => $internalId,
                'is_active' => true,
            ],
            [
                'code' => 'INTERNAL_CONSUMPTION',
                'name' => 'Internal Consumption',
                'description' => 'Lote destinado a consumo interno',
                'icon' => 'heroicons-outline:home',
                'color' => '#3b82f6',
                'activity_id' => $internalId,
                'is_active' => true,
            ],
            [
                'code' => 'INTERNAL_DEATH',
                'name' => 'Internal Death',
                'description' => 'Lote asociado a bajas o mortalidad interna',
                'icon' => 'heroicons-outline:x-circle',
                'color' => '#ef4444',
                'activity_id' => $internalId,
                'is_active' => true,
            ],
        ];

        // 1. Seed canonical batch types. `firstOrCreate` does not touch pre-existing
        //    rows, so the catalogue attributes are applied with an explicit update.
        foreach ($batchTypes as $data) {
            $data['is_selectable'] = $data['is_selectable'] ?? true;

            BatchType::firstOrCreate(['code' => $data['code']], $data);

            BatchType::where('code', $data['code'])->update([
                'activity_id'   => $data['activity_id'],
                'is_selectable' => $data['is_selectable'],
            ]);
        }

        // 2. Attach canonical types to all existing companies via company_batch_type pivot
        $allBatchTypes = BatchType::all();
        $companies = Company::all();

        foreach ($companies as $company) {
            foreach ($allBatchTypes as $type) {
                CompanyBatchType::firstOrCreate(
                    [
                        'company_id' => $company->id,
                        'batch_type_id' => $type->id,
                    ],
                    [
                        'is_enabled' => true,
                    ]
                );
            }
        }
    }
}
