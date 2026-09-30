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
            SecondaryCompanyLivestockSeeder::class,
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
            // Contraparte de los crudos: tres toros con todos los parámetros correctos (APTOS).
            FitTestBullsSeeder::class,
            // Crías al pie DST-T-01..46 para las planillas de prueba DEST-01 (ai-agent/image_test/dest001).
            WeaningTestCalvesSeeder::class,
            // Vientres preñados PAR-V-01..36 y la orden de parición PA-20260929-0001 (PAR-01).
            BirthOrderTestSeeder::class,
            ActivityChangeTestAnimalsSeeder::class,
            Cact01ActivityChangeScanTestSeeder::class,
            // Escenario "Zoo": cada etapa del rodeo con sus animales reales y un destino vacío por
            // etapa, para probar las órdenes de transferencia contra la zootecnia.
            TransferOrderZootechnicsTestSeeder::class,
            // Ejemplos 3.1, 3.3 y 3.5 del plan de curva de peso: un lote cuyo promedio baja
            // porque se clasificaron los pesados, un destino que ya tenía animales, y un
            // lote que queda vacío. Sirven para mirar en pantalla que ninguna caída es
            // una pérdida de peso.
            BatchCompositionCurveSeeder::class,
            // Simulación histórica de ingresos progresivos en 'lote ejemplo peso 1' a lo largo de 5 meses.
            SimulateBatchProgressiveEntriesSeeder::class,
            // Dos lotes vacíos para pruebas interactivas de usuario: 'LOTE DE INGRESO ANIMALES TEST' y 'LOTE DESTINO EGRESO ANIMALES TEST'
            TestGraphBatchesSeeder::class,
            // Caravanas con número electrónico (032000000000001..012) que el simulador del lector BLE
            // vuelve a leer: ya registradas en la empresa activa y en Hacienda Secundaria.
            BleReaderTestCaravansSeeder::class,
        ]);
    }
}
