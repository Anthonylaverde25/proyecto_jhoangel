<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\AnimalSubcategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\Company;
use App\Models\GestationLossReason;
use Illuminate\Database\Seeder;

final class AbortionTestSeeder extends Seeder
{
    public const BATCH_NAME = 'Prueba Aborto';

    public function run(): void
    {
        $company = Company::first();
        if ($company === null) {
            $this->command?->warn('AbortionTestSeeder: no hay compañía cargada. Se omite.');
            return;
        }

        $companyId = (int) $company->id;

        $categories = AnimalCategory::withoutGlobalScopes()
            ->whereIn('code', ['VACA', 'VAQUILLONA'])
            ->pluck('id', 'code');

        $vacaCatId = (int) ($categories['VACA'] ?? 0);
        $vaquillonaCatId = (int) ($categories['VAQUILLONA'] ?? 0);

        $subcategories = AnimalSubcategory::withoutGlobalScopes()
            ->whereIn('code', ['REPOSICION', 'RODEO_GENERAL'])
            ->pluck('id', 'code');

        $reposicionSubcatId = (int) ($subcategories['REPOSICION'] ?? 0);
        $rodeoGeneralSubcatId = (int) ($subcategories['RODEO_GENERAL'] ?? 0);

        // Ensure GestationLossReason exists for ABORTION
        GestationLossReason::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'code' => 'ABORTION'],
            ['name' => 'Aborto', 'description' => 'Pérdida gestacional espontánea', 'is_active' => true]
        );

        $activityId = Activity::withoutGlobalScopes()->where('code', 'CRIA')->value('id')
            ?? Activity::withoutGlobalScopes()->first()?->id;
        $batchTypeId = BatchType::withoutGlobalScopes()->first()?->id;

        /** @var Batch $batch */
        $batch = Batch::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'name' => self::BATCH_NAME],
            [
                'activity_id' => $activityId,
                'batch_type_id' => $batchTypeId,
                'observaciones' => 'Lote propio para pruebas del módulo de aborto (Entrega C2).',
            ]
        );

        $batchId = (int) $batch->id;

        // 1. Vaquillona 1 (5 meses de preñez)
        $vaq1 = Caravan::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'identification' => 'ABT-V01'],
            [
                'batch_id' => $batchId,
                'sex' => 'H',
                'category_id' => $vaquillonaCatId,
                'subcategory_id' => $reposicionSubcatId,
                'teeth' => 2,
            ]
        );

        CaravanGestation::withoutGlobalScopes()->updateOrCreate(
            ['caravan_id' => $vaq1->id, 'is_current' => true],
            [
                'start_date' => now()->subMonths(5)->toDateString(),
                'estimated_due_date' => now()->addMonths(4)->toDateString(),
                'success' => null,
            ]
        );

        // 2. Vaquillona 2 (7 meses de preñez - aborto tardío candidata a vaca)
        $vaq2 = Caravan::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'identification' => 'ABT-V02'],
            [
                'batch_id' => $batchId,
                'sex' => 'H',
                'category_id' => $vaquillonaCatId,
                'subcategory_id' => $reposicionSubcatId,
                'teeth' => 4,
            ]
        );

        CaravanGestation::withoutGlobalScopes()->updateOrCreate(
            ['caravan_id' => $vaq2->id, 'is_current' => true],
            [
                'start_date' => now()->subMonths(7)->toDateString(),
                'estimated_due_date' => now()->addMonths(2)->toDateString(),
                'success' => null,
            ]
        );

        // 3. Vaca multípara preñada (no muestra casilla "Pasa a vaca")
        $cow1 = Caravan::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId, 'identification' => 'ABT-C01'],
            [
                'batch_id' => $batchId,
                'sex' => 'H',
                'category_id' => $vacaCatId,
                'subcategory_id' => $rodeoGeneralSubcatId,
                'teeth' => 6,
            ]
        );

        CaravanGestation::withoutGlobalScopes()->updateOrCreate(
            ['caravan_id' => $cow1->id, 'is_current' => true],
            [
                'start_date' => now()->subMonths(5)->toDateString(),
                'estimated_due_date' => now()->addMonths(4)->toDateString(),
                'success' => null,
            ]
        );

        $this->command?->info("AbortionTestSeeder: Lote '{$batch->name}' (ID: {$batch->id}) con 3 hembras preñadas (ABT-V01, ABT-V02, ABT-C01).");
    }
}

