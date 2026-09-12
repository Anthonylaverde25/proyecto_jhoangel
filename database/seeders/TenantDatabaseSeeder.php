<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TenantDatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            DocumentStatusSeeder::class,
            GestationLossReasonSeeder::class,
            CaravanFieldMappingSeeder::class,
            BreedSeeder::class,
            ColorSeeder::class,
            AnimalCategorySeeder::class,
            LivestockHierarchySeeder::class,
            ActivitySeeder::class,
            BatchWeightSeeder::class,
            WorkTemplateSeeder::class,
            BatchTypeSeeder::class,
            PathogenSeeder::class,
            BullHealthSeeder::class,
            // Sanitary diagnostics module: catalogues, evidentiary protocols, portal access
            // links, and a final aptitude recomputation under the venereal rule (ADR-4).
            VeterinaryCatalogSeeder::class,
            DiagnosticProtocolSeeder::class,
            // Actas de extracción en sus cuatro estados (ADR-11 / ADR-13 / ADR-17).
            ExtractionActSeeder::class,
            VeterinaryPortalAccessTokenSeeder::class,
            BullAptitudeRecalculationSeeder::class,
            // Va último a propósito: el recálculo anterior crea una evaluación para todo toro que
            // no tenga ninguna, y estos tres deben quedar sin historia sanitaria alguna.
            UnevaluatedTestBullsSeeder::class,
        ]);
    }
}
