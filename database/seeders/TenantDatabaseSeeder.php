<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * What a new tenant starts with: catalogs, partners, stock and a clean rodeo. The test scenarios,
 * each with its own batches and tags, are not part of it: see TestScenariosSeeder.
 */
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
            DeathCauseSeeder::class,
            CaravanFieldMappingSeeder::class,
            BreedSeeder::class,
            ColorSeeder::class,
            AnimalCategorySeeder::class,
            LivestockHierarchySeeder::class,
            ActivitySeeder::class,
            // Catalog of weighing purposes; every seeder below that weighs depends on it.
            WeighingTypeSeeder::class,
            WorkTemplateSeeder::class,
            BatchTypeSeeder::class,
            SecondaryCompanyLivestockSeeder::class,
            PathogenSeeder::class,
            BullHealthSeeder::class,
            // The rodeo: 10 active batches, every animal with its category and its weights. It needs
            // the bulls above, and the stock examples below hang from its batches.
            RodeoDemoSeeder::class,
            // Base units and categories for every company (the company observer does not run here),
            // then the catalog that backs the B1 screens: it needs the providers seeded above.
            CompanyItemCatalogSeeder::class,
            ItemCatalogTestSeeder::class,
            FiscalLineTestSeeder::class,
            // Provider documents and stock operations of the B2 screens, the delivery notes of
            // delivery C and the warehouses and transfers of delivery D, through the use cases.
            StockLedgerTestSeeder::class,
            DeliveryNoteTestSeeder::class,
            WarehouseTestSeeder::class,
            // Semen stock (semen S-A): tanks, sires, lots and straws without lot.
            SemenStockTestSeeder::class,
            // Sanitary diagnostics module: catalogues, evidentiary protocols, portal access
            // links, and a final aptitude recomputation under the venereal rule (ADR-4).
            VeterinaryCatalogSeeder::class,
            DiagnosticProtocolSeeder::class,
            // Actas de extracción en sus cuatro estados (ADR-11 / ADR-13 / ADR-17).
            ExtractionActSeeder::class,
            VeterinaryPortalAccessTokenSeeder::class,
            BullAptitudeRecalculationSeeder::class,
            // After the recalculation above: a service only admits a bull that is fit by then.
            RodeoDemoServicesSeeder::class,
        ]);
    }
}
