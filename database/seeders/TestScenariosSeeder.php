<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The test scenarios, on top of the base seed: printed sheets and scans (DEST-01, PAR-01, CACT-01,
 * ING), the BLE reader, service, castration and weight-curve cases. Each one brings its own batches
 * and tags, so running them all adds about 40 batches to the 10 of the rodeo. For one scenario,
 * seed its class alone.
 *     php artisan tenants:seed --class=TestScenariosSeeder
 */
class TestScenariosSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            LegacyLivestockScenarioSeeder::class,
            // AI service orders (semen S-B): a batch ready for AI and one already inseminating.
            InseminationServiceTestSeeder::class,
            // Tres toros sin historia sanitaria alguna y su contraparte con todos los parámetros
            // correctos (APTOS).
            UnevaluatedTestBullsSeeder::class,
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
            // Ejemplos 3.1, 3.3 y 3.5 del plan de curva de peso: un lote cuyo promedio baja porque se
            // clasificaron los pesados, un destino que ya tenía animales, y un lote que queda vacío.
            BatchCompositionCurveSeeder::class,
            // Simulación histórica de ingresos progresivos en 'lote ejemplo peso 1' a lo largo de 5 meses.
            SimulateBatchProgressiveEntriesSeeder::class,
            // Dos lotes vacíos para pruebas interactivas de usuario.
            TestGraphBatchesSeeder::class,
            // Caravanas con número electrónico (032000000000001..012) que el simulador del lector BLE
            // vuelve a leer: ya registradas en la empresa activa y en Hacienda Secundaria.
            BleReaderTestCaravansSeeder::class,
            // Órdenes de ingreso de hacienda externa (ING-02): una en espera de DTE, una parcial con
            // su DTE cargado (ING-T-01..06) y un borrador, para probar /batches/external.
            EntryOrderTestSeeder::class,
            // Servicio sobre lote operacional: un rodeo en servicio con ingresos y salidas, uno con
            // tacto cargado (3 vacías) y uno listo para "Iniciar servicio" con toros aptos libres.
            ServiceOnOperationalBatchTestSeeder::class,
            // Entrega C1: Lote de prueba de castración con machos castrables y casos de borde.
            CastrationTestSeeder::class,
        ]);
    }
}
