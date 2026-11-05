<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\CaravanLineage;
use App\Models\Company;
use Illuminate\Database\Seeder;

final class CastrationTestSeeder extends Seeder
{
    public const BATCH_NAME = 'Prueba Castración';

    public function run(): void
    {
        $company = Company::first();
        if ($company === null) {
            $this->command?->warn('CastrationTestSeeder: no hay compañía cargada. Se omite.');
            return;
        }

        $companyId = (int) $company->id;

        $categories = AnimalCategory::withoutGlobalScopes()
            ->whereIn('code', ['VACA', 'TERNERO', 'TORITO', 'NOVILLITO'])
            ->pluck('id', 'code');

        $vacaCatId = (int) ($categories['VACA'] ?? 0);
        $terneroCatId = (int) ($categories['TERNERO'] ?? 0);
        $toritoCatId = (int) ($categories['TORITO'] ?? 0);
        $novillitoCatId = (int) ($categories['NOVILLITO'] ?? 0);

        $activityId = Activity::withoutGlobalScopes()->where('code', 'RECRIA')->value('id')
            ?? Activity::withoutGlobalScopes()->first()?->id;
        $batchTypeId = BatchType::withoutGlobalScopes()->first()?->id;

        /** @var Batch $batch */
        $batch = Batch::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'name' => self::BATCH_NAME],
            [
                'activity_id' => $activityId,
                'batch_type_id' => $batchTypeId,
                'observaciones' => 'Lote propio para pruebas interactivas del módulo de castración.',
            ]
        );

        $batchId = (int) $batch->id;

        // 1. Mother for animals with lineage
        $mother = Caravan::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'identification' => 'CST-V01'],
            [
                'batch_id' => $batchId,
                'sex' => 'H',
                'category_id' => $vacaCatId,
                'teeth' => 6,
                'entry_weight' => 450.0,
            ]
        );

        $motherId = (int) $mother->id;

        // 2. Terneros machos con nacimiento (~ 3 meses)
        $this->seedAnimal($companyId, $batchId, 'CST-T01', 'M', $terneroCatId, '2026-08-01', $motherId);
        $this->seedAnimal($companyId, $batchId, 'CST-T02', 'M', $terneroCatId, '2026-08-05', $motherId);

        // 3. Terneros machos comprados (sin fecha de nacimiento)
        $this->seedAnimal($companyId, $batchId, 'CST-T03', 'M', $terneroCatId, null, null);
        $this->seedAnimal($companyId, $batchId, 'CST-T04', 'M', $terneroCatId, null, null);

        // 4. Ternera hembra (para probar rechazo "sólo se castran machos")
        $this->seedAnimal($companyId, $batchId, 'CST-H01', 'H', $terneroCatId, '2026-08-10', $motherId);

        // 5. Torito con nacimiento ~ 15 meses (preselección Novillito)
        $this->seedAnimal($companyId, $batchId, 'CST-TO1', 'M', $toritoCatId, '2025-08-01', $motherId);

        // 6. Torito con nacimiento ~ 22 meses (preselección Novillo)
        $this->seedAnimal($companyId, $batchId, 'CST-TO2', 'M', $toritoCatId, '2025-01-01', $motherId);

        // 7. Torito comprado (sin fecha de nacimiento, "Pasa a" obligatorio)
        $this->seedAnimal($companyId, $batchId, 'CST-TO3', 'M', $toritoCatId, null, null);

        // 8. Novillito (para probar rechazo "ya es castrado por su categoría")
        $this->seedAnimal($companyId, $batchId, 'CST-N01', 'M', $novillitoCatId, null, null);
    }

    private function seedAnimal(
        int $companyId,
        int $batchId,
        string $tag,
        string $sex,
        int $categoryId,
        ?string $birthDate,
        ?int $motherId
    ): void {
        /** @var Caravan $caravan */
        $caravan = Caravan::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'identification' => $tag],
            [
                'batch_id' => $batchId,
                'sex' => $sex,
                'category_id' => $categoryId,
                'teeth' => 0,
                'entry_weight' => 200.0,
            ]
        );

        if ($birthDate !== null && $motherId !== null) {
            CaravanLineage::withoutGlobalScopes()->firstOrCreate(
                ['caravan_id' => $caravan->id],
                [
                    'mother_id' => $motherId,
                    'birth_date' => $birthDate,
                ]
            );
        }
    }
}
