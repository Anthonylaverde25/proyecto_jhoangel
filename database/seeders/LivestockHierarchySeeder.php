<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LivestockHierarchySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Limpiar tablas para permitir re-ejecución (idempotencia)
        DB::table('service_order_females')->delete();
        DB::table('service_order_males')->delete();
        DB::table('service_orders')->delete();
        DB::table('caravan_gestations')->delete();
        DB::table('female_caravan_details')->delete();
        DB::table('caravans')->delete();
        DB::table('batches')->delete();
        DB::table('farms')->delete();
        DB::table('providers')->delete();

        $serviceOrderData = null;

        // Obtener razas disponibles
        $breedIds = DB::table('breeds')->pluck('id')->toArray();
        $categories = ['novillito', 'novillo', 'vaquillona', 'vaca', 'vaca_vacia', 'ternero', 'toro'];
        
        $categoriesMap = DB::table('animal_categories')->pluck('id', 'code')->toArray();
        $subcategoriesMap = DB::table('animal_subcategories')->pluck('id', 'code')->toArray();

        $getCategoryIds = function (string $cat) use ($categoriesMap, $subcategoriesMap): array {
            $normalized = strtoupper($cat);
            if ($normalized === 'VACA_VACIA' || $normalized === 'VACA') {
                return [$categoriesMap['VACA'] ?? null, $subcategoriesMap['RODEO_GENERAL'] ?? null];
            }
            if ($normalized === 'VAQUILLONA') {
                return [$categoriesMap['VAQUILLONA'] ?? null, $subcategoriesMap['REPOSICION'] ?? null];
            }
            if ($normalized === 'TERNERA' || $normalized === 'TERNERO') {
                return [$categoriesMap['TERNERO'] ?? null, null];
            }
            if ($normalized === 'NOVILLITO') {
                return [$categoriesMap['NOVILLITO'] ?? null, null];
            }
            if ($normalized === 'NOVILLO') {
                return [$categoriesMap['NOVILLO'] ?? null, null];
            }
            if ($normalized === 'TORITO') {
                return [$categoriesMap['TORITO'] ?? null, null];
            }
            if ($normalized === 'TORO') {
                return [$categoriesMap['TORO'] ?? null, null];
            }
            return [$categoriesMap[$normalized] ?? null, null];
        };

        // Obtener la primera empresa disponible
        $companyId = DB::table('companies')->first()->id;

        // 1. Crear Proveedores
        $provider1Id = DB::table('providers')->insertGetId([
            'name' => 'Estancia El Trébol S.A.',
            'commercial_name' => 'El Trébol',
            'cuit' => '30-12345678-9',
            'location' => 'Ruta 5, Km 150, Chivilcoy',
            'email' => 'administracion@eltrebol.com',
            'phone' => '+54 2346 123456',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $provider2Id = DB::table('providers')->insertGetId([
            'name' => 'Ganadera del Sur SRL',
            'commercial_name' => 'Ganadera Sur',
            'cuit' => '30-98765432-1',
            'location' => 'Ruta 226, Km 40, Balcarce',
            'email' => 'ventas@ganaderasur.com',
            'phone' => '+54 2266 987654',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $providerIds = [$provider1Id, $provider2Id];
        $names = ['El Trébol', 'Ganadera Sur'];

        // 2. Crear Granjas (2 por proveedor + 2 fincas propias)
        $ownFarm1Id = DB::table('farms')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Establecimiento La Juanita',
            'location' => 'Ruta 7, Km 220, Junín',
            'renspa' => '02.001.0.00001/01',
            'provider_id' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ownFarm2Id = DB::table('farms')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Campo San Jorge',
            'location' => 'Ruta 188, Km 85, Pergamino',
            'renspa' => '02.001.0.00002/01',
            'provider_id' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ownBatch1Id = DB::table('batches')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Corral Invernada 1',
            'farm_id' => $ownFarm1Id,
            'observaciones' => 'Lote propio de engorde intensivo.',
            'current_weight' => 385.0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ownBatch2Id = DB::table('batches')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Rodeo de Cría A',
            'farm_id' => $ownFarm1Id,
            'observaciones' => 'Lote propio de vientres y reproducción.',
            'current_weight' => 420.0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ownBatchTestCriaId = DB::table('batches')->insertGetId([
            'company_id' => $companyId,
            'name' => 'LOTE TEST CRIA',
            'farm_id' => $ownFarm1Id,
            'observaciones' => 'Lote propio de prueba para cría, entore y servicio reproductivo.',
            'current_weight' => 430.0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($providerIds as $index => $providerId) {
            $farm1Id = DB::table('farms')->insertGetId([
                'company_id' => $companyId,
                'name' => 'Sección A - ' . $names[$index],
                'location' => 'Norte del establecimiento',
                'renspa' => '01.0' . ($index + 1) . '.0.00001/01',
                'provider_id' => $providerId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $farm2Id = DB::table('farms')->insertGetId([
                'company_id' => $companyId,
                'name' => 'Sección B - ' . $names[$index],
                'location' => 'Sur del establecimiento',
                'renspa' => '01.0' . ($index + 1) . '.0.00002/01',
                'provider_id' => $providerId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $farmIds = [$farm1Id, $farm2Id];


            // 3. Crear Lotes (2 por granja)
            foreach ($farmIds as $farmId) {
                $batch1Id = DB::table('batches')->insertGetId([
                    'company_id' => $companyId,
                    'name' => 'Lote Invierno - ' . $farmId,
                    'farm_id' => $farmId,
                    'observaciones' => 'Lote destinado a invernada.',
                    'current_weight' => 320.5,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $batch2Id = DB::table('batches')->insertGetId([
                    'company_id' => $companyId,
                    'name' => 'Lote Recría - ' . $farmId,
                    'farm_id' => $farmId,
                    'observaciones' => 'Animales en fase de recría.',
                    'current_weight' => 210.8,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $batchIds = [$batch1Id, $batch2Id];

                // Obtener motivos de pérdida gestacional para la empresa
                $lossReasons = DB::table('gestation_loss_reasons')
                    ->where('company_id', $companyId)
                    ->pluck('id')
                    ->toArray();

                // 4. Crear Caravanas (5 por lote)
                foreach ($batchIds as $batchId) {
                    $isTrebolRecria = ($index === 0 && $farmId === $farm1Id && $batchId === $batch2Id);

                    if ($isTrebolRecria) {
                        $specificCaravans = [
                            ['ident' => 'CAR-2-1-274', 'cat' => 'vaca'],
                            ['ident' => 'CAR-2-2-413', 'cat' => 'vaca_vacia'],
                            ['ident' => 'CAR-2-3-240', 'cat' => 'vaca'],
                            ['ident' => 'CAR-2-4-388', 'cat' => 'vaca'],
                            ['ident' => 'CAR-2-5-569', 'cat' => 'vaca'],
                        ];

                        $femaleIdsForServiceOrder = [];

                        foreach ($specificCaravans as $sc) {
                            [$catId, $subId] = $getCategoryIds($sc['cat']);

                            $caravanId = DB::table('caravans')->insertGetId([
                                'company_id' => $companyId,
                                'batch_id' => $batchId,
                                'identification' => $sc['ident'],
                                'category_id' => $catId,
                                'subcategory_id' => $subId,
                                'breed_id' => !empty($breedIds) ? $breedIds[array_rand($breedIds)] : null,
                                'sex' => 'H',
                                'teeth' => rand(2, 6),
                                'entry_weight' => rand(280, 350) + (rand(0, 99) / 100),
                                'entry_date' => now()->subDays(rand(100, 300)),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                            DB::table('female_caravan_details')->insert([
                                'caravan_id' => $caravanId,
                                'is_empty' => true,
                                'arrival_category' => $sc['cat'],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                            $femaleIdsForServiceOrder[] = $caravanId;
                        }

                        // Save details to create Service Order at the end of the seeder
                        $serviceOrderData = [
                            'company_id' => $companyId,
                            'batch_id' => $batchId,
                            'female_ids' => $femaleIdsForServiceOrder
                        ];
                    } else {
                        for ($i = 1; $i <= 5; $i++) {
                            $sex = rand(0, 1) ? 'M' : 'H';
                            $category = $categories[array_rand($categories)];
                            
                            // Si el sexo es macho, no puede ser vaca/vaquillona. Si es hembra, no puede ser novillo/toro.
                            if ($sex === 'M') {
                                $category = in_array($category, ['toro', 'novillo', 'novillito', 'ternero']) ? $category : 'novillo';
                            } else {
                                $category = in_array($category, ['vaca', 'vaca_vacia', 'vaquillona', 'ternera']) ? $category : 'vaca';
                            }

                            [$catId, $subId] = $getCategoryIds($category);

                            $caravanId = DB::table('caravans')->insertGetId([
                                'company_id' => $companyId,
                                'batch_id' => $batchId,
                                'identification' => 'CAR-' . $batchId . '-' . $i . '-' . rand(100, 999),
                                'category_id' => $catId,
                                'subcategory_id' => $subId,
                                'breed_id' => !empty($breedIds) ? $breedIds[array_rand($breedIds)] : null,
                                'sex' => $sex,
                                'teeth' => rand(0, 8),
                                'entry_weight' => rand(150, 450) + (rand(0, 99) / 100),
                                'entry_date' => now()->subDays(rand(1, 365)),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                            // Lógica reproductiva para hembras
                            if ($sex === 'H') {
                                $roll = rand(1, 100);
                                $isEmpty = true;
                                $hasActive = false;

                                // Crear detalles reproductivos de la hembra
                                DB::table('female_caravan_details')->insert([
                                    'caravan_id' => $caravanId,
                                    'is_empty' => $isEmpty,
                                    'arrival_category' => $category,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);

                                // 30% probabilidad de gestación pasada terminada en pérdida
                                if ($roll > 20 && $roll <= 50 && !empty($lossReasons)) {
                                    DB::table('caravan_gestations')->insert([
                                        'caravan_id' => $caravanId,
                                        'start_date' => now()->subMonths(9)->format('Y-m-d'),
                                        'estimated_due_date' => now()->subMonths(4)->format('Y-m-d'),
                                        'is_current' => false,
                                        'success' => false,
                                        'end_date' => now()->subMonths(5)->format('Y-m-d'),
                                        'loss_reason_id' => $lossReasons[array_rand($lossReasons)],
                                        'loss_notes' => 'Pérdida gestacional aleatoria en lote.',
                                        'gestation_stage' => 'body',
                                        'gestation_months' => 4.0,
                                        'notes' => 'Gestación histórica fallida.',
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                }

                                // 20% probabilidad de gestación pasada exitosa
                                if ($roll > 50 && $roll <= 70) {
                                    DB::table('caravan_gestations')->insert([
                                        'caravan_id' => $caravanId,
                                        'start_date' => now()->subMonths(11)->format('Y-m-d'),
                                        'estimated_due_date' => now()->subMonths(2)->format('Y-m-d'),
                                        'is_current' => false,
                                        'success' => true,
                                        'end_date' => now()->subMonths(2)->format('Y-m-d'),
                                        'gestation_stage' => 'head',
                                        'gestation_months' => 9.0,
                                        'notes' => 'Parición exitosa anterior.',
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                }
                            }
                        }
                    }
                }
            }
        }

        // 4. Crear Caravanas Propias en los Lotes Propios
        $ownBatchesToSeed = [
            ['id' => $ownBatch1Id, 'prefix' => 'INV-P', 'cat' => 'novillito', 'sex' => 'M', 'weight' => 385.0],
            ['id' => $ownBatch2Id, 'prefix' => 'CRIA-P', 'cat' => 'vaca', 'sex' => 'H', 'weight' => 420.0],
        ];

        foreach ($ownBatchesToSeed as $ownB) {
            for ($k = 1; $k <= 6; $k++) {
                [$catId, $subId] = $getCategoryIds($ownB['cat']);

                $cId = DB::table('caravans')->insertGetId([
                    'company_id' => $companyId,
                    'batch_id' => $ownB['id'],
                    'identification' => $ownB['prefix'] . '-' . str_pad((string)$k, 3, '0', STR_PAD_LEFT),
                    'category_id' => $catId,
                    'subcategory_id' => $subId,
                    'breed_id' => !empty($breedIds) ? $breedIds[array_rand($breedIds)] : null,
                    'sex' => $ownB['sex'],
                    'teeth' => $ownB['sex'] === 'H' ? 4 : 2,
                    'entry_weight' => $ownB['weight'] + ($k * 5.5),
                    'renspa' => '02.001.0.00001/01',
                    'provider_id' => null,
                    'entry_date' => now()->subDays(60),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('caravan_weights')->insert([
                    'caravan_id' => $cId,
                    'weight' => $ownB['weight'] + ($k * 5.5),
                    'weighing_date' => now()->subDays(60)->format('Y-m-d'),
                    'current' => true,
                    'notes' => 'Pesaje inicial lote propio',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);


                if ($ownB['sex'] === 'H') {
                    DB::table('female_caravan_details')->insert([
                        'caravan_id' => $cId,
                        'is_empty' => true,
                        'arrival_category' => $ownB['cat'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // === SEEDING LOTE TEST CRIA (20 HEMBRAS DE CRÍA + 6 TOROS REPRODUCTORES) ===
        $testCriaFemales = [
            ['ident' => 'CRIA-001', 'cat' => 'vaca', 'teeth' => 4, 'weight' => 440.0],
            ['ident' => 'CRIA-002', 'cat' => 'vaca', 'teeth' => 6, 'weight' => 455.5],
            ['ident' => 'CRIA-003', 'cat' => 'vaca', 'teeth' => 4, 'weight' => 430.0],
            ['ident' => 'CRIA-004', 'cat' => 'vaquillona', 'teeth' => 2, 'weight' => 380.0],
            ['ident' => 'CRIA-005', 'cat' => 'vaquillona', 'teeth' => 2, 'weight' => 395.0],
            ['ident' => 'CRIA-006', 'cat' => 'vaca', 'teeth' => 4, 'weight' => 460.0],
            ['ident' => 'CRIA-007', 'cat' => 'vaca', 'teeth' => 6, 'weight' => 470.0],
            ['ident' => 'CRIA-008', 'cat' => 'vaquillona', 'teeth' => 2, 'weight' => 385.0],
            ['ident' => 'CRIA-009', 'cat' => 'vaca', 'teeth' => 4, 'weight' => 445.0],
            ['ident' => 'CRIA-010', 'cat' => 'vaca', 'teeth' => 4, 'weight' => 435.0],
            ['ident' => 'CRIA-011', 'cat' => 'vaquillona', 'teeth' => 2, 'weight' => 390.0],
            ['ident' => 'CRIA-012', 'cat' => 'vaca', 'teeth' => 6, 'weight' => 480.0],
            ['ident' => 'CRIA-013', 'cat' => 'vaca', 'teeth' => 4, 'weight' => 425.0],
            ['ident' => 'CRIA-014', 'cat' => 'vaquillona', 'teeth' => 2, 'weight' => 400.0],
            ['ident' => 'CRIA-015', 'cat' => 'vaca', 'teeth' => 6, 'weight' => 465.0],
            ['ident' => 'CRIA-016', 'cat' => 'vaca', 'teeth' => 4, 'weight' => 450.0],
            ['ident' => 'CRIA-017', 'cat' => 'vaquillona', 'teeth' => 2, 'weight' => 388.0],
            ['ident' => 'CRIA-018', 'cat' => 'vaca', 'teeth' => 4, 'weight' => 442.0],
            ['ident' => 'CRIA-019', 'cat' => 'vaca', 'teeth' => 6, 'weight' => 475.0],
            ['ident' => 'CRIA-020', 'cat' => 'vaca', 'teeth' => 4, 'weight' => 458.0],
        ];

        foreach ($testCriaFemales as $tf) {
            [$catId, $subId] = $getCategoryIds($tf['cat']);
            $caravanId = DB::table('caravans')->insertGetId([
                'company_id' => $companyId,
                'batch_id' => $ownBatchTestCriaId,
                'identification' => $tf['ident'],
                'category_id' => $catId,
                'subcategory_id' => $subId,
                'breed_id' => !empty($breedIds) ? $breedIds[array_rand($breedIds)] : null,
                'sex' => 'H',
                'teeth' => $tf['teeth'],
                'entry_weight' => $tf['weight'],
                'renspa' => '02.001.0.00001/01',
                'provider_id' => null,
                'entry_date' => now()->subDays(rand(30, 90)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('caravan_weights')->insert([
                'caravan_id' => $caravanId,
                'weight' => $tf['weight'],
                'weighing_date' => now()->subDays(rand(10, 30))->format('Y-m-d'),
                'current' => true,
                'notes' => 'Pesaje control vientre lote cría',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('female_caravan_details')->insert([
                'caravan_id' => $caravanId,
                'is_empty' => true,
                'arrival_category' => $tf['cat'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 6 Toros Reproductores para Testing
        $testCriaBulls = [
            ['ident' => 'TORO-TEST-01', 'teeth' => 4, 'weight' => 680.0],
            ['ident' => 'TORO-TEST-02', 'teeth' => 6, 'weight' => 720.0],
            ['ident' => 'TORO-TEST-03', 'teeth' => 4, 'weight' => 710.0],
            ['ident' => 'TORO-TEST-04', 'teeth' => 4, 'weight' => 650.0],
            ['ident' => 'TORO-TEST-05', 'teeth' => 6, 'weight' => 740.0],
            ['ident' => 'TORO-TEST-06', 'teeth' => 4, 'weight' => 760.0],
        ];

        [$toroCatId, $toroSubId] = $getCategoryIds('toro');

        foreach ($testCriaBulls as $tb) {
            $caravanId = DB::table('caravans')->insertGetId([
                'company_id' => $companyId,
                'batch_id' => $ownBatchTestCriaId,
                'identification' => $tb['ident'],
                'category_id' => $toroCatId,
                'subcategory_id' => $toroSubId,
                'breed_id' => !empty($breedIds) ? $breedIds[array_rand($breedIds)] : null,
                'sex' => 'M',
                'teeth' => $tb['teeth'],
                'entry_weight' => $tb['weight'],
                'renspa' => '02.001.0.00001/01',
                'provider_id' => null,
                'entry_date' => now()->subDays(rand(60, 180)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('caravan_weights')->insert([
                'caravan_id' => $caravanId,
                'weight' => $tb['weight'],
                'weighing_date' => now()->subDays(rand(10, 30))->format('Y-m-d'),
                'current' => true,
                'notes' => 'Pesaje reproductor toro test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // === ESCENARIOS DE GESTACIÓN Y PEDIGREE ===
        
        // Obtener un lote propio para asociar las nuevas caravanas
        $batchId = $ownBatch2Id;
        $breedId = !empty($breedIds) ? $breedIds[0] : null;


        // --- ESCENARIO 1: Gestación Activa con Múltiples Padres Potenciales ---
        // 1. Crear Toros (padres potenciales)
        [$toroCatId, $toroSubId] = $getCategoryIds('toro');

        $toroPot1Id = DB::table('caravans')->insertGetId([
            'company_id' => $companyId,
            'batch_id' => $batchId,
            'identification' => 'TORO-POT-01',
            'category_id' => $toroCatId,
            'subcategory_id' => $toroSubId,
            'breed_id' => $breedId,
            'sex' => 'M',
            'teeth' => 4,
            'entry_weight' => 520.0,
            'entry_date' => now()->subMonths(6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $toroPot2Id = DB::table('caravans')->insertGetId([
            'company_id' => $companyId,
            'batch_id' => $batchId,
            'identification' => 'TORO-POT-02',
            'category_id' => $toroCatId,
            'subcategory_id' => $toroSubId,
            'breed_id' => $breedId,
            'sex' => 'M',
            'teeth' => 4,
            'entry_weight' => 510.5,
            'entry_date' => now()->subMonths(6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Crear Madre (preñada)
        [$vacaCatId, $vacaSubId] = $getCategoryIds('vaca');

        $vacaPreg1Id = DB::table('caravans')->insertGetId([
            'company_id' => $companyId,
            'batch_id' => $batchId,
            'identification' => 'VACA-PREG-01',
            'category_id' => $vacaCatId,
            'subcategory_id' => $vacaSubId,
            'breed_id' => $breedId,
            'sex' => 'H',
            'teeth' => 6,
            'entry_weight' => 420.0,
            'entry_date' => now()->subMonths(6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Detalles reproductivos: vacía (sin gestación activa inicial)
        DB::table('female_caravan_details')->insert([
            'caravan_id' => $vacaPreg1Id,
            'is_empty' => true,
            'arrival_category' => 'vaca',
            'created_at' => now(),
            'updated_at' => now(),
        ]);


        // --- ESCENARIO 2: Parto Exitoso y Cría al Pie (Nursing) ---
        // 1. Crear Toro (padre confirmado)
        $toroConf2Id = DB::table('caravans')->insertGetId([
            'company_id' => $companyId,
            'batch_id' => $batchId,
            'identification' => 'TORO-CONF-02',
            'category_id' => $toroCatId,
            'subcategory_id' => $toroSubId,
            'breed_id' => $breedId,
            'sex' => 'M',
            'teeth' => 6,
            'entry_weight' => 540.0,
            'entry_date' => now()->subMonths(12),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Crear Madre
        $vacaNurs2Id = DB::table('caravans')->insertGetId([
            'company_id' => $companyId,
            'batch_id' => $batchId,
            'identification' => 'VACA-NURS-02',
            'category_id' => $vacaCatId,
            'subcategory_id' => $vacaSubId,
            'breed_id' => $breedId,
            'sex' => 'H',
            'teeth' => 4,
            'entry_weight' => 430.5,
            'entry_date' => now()->subMonths(12),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Detalles reproductivos: vacía (porque ya parió)
        DB::table('female_caravan_details')->insert([
            'caravan_id' => $vacaNurs2Id,
            'is_empty' => true,
            'arrival_category' => 'vaca',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Gestación cerrada exitosa
        $gestation2Id = DB::table('caravan_gestations')->insertGetId([
            'caravan_id' => $vacaNurs2Id,
            'start_date' => now()->subMonths(10)->format('Y-m-d'),
            'estimated_due_date' => now()->subMonths(1)->format('Y-m-d'),
            'is_current' => false,
            'success' => true,
            'end_date' => now()->subMonths(1)->format('Y-m-d'),
            'gestation_stage' => 'head',
            'gestation_months' => 9.0,
            'notes' => 'Parto natural registrado con éxito.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Registrar padre en la gestación
        DB::table('gestation_sires')->insert([
            'gestation_id' => $gestation2Id,
            'sire_id' => $toroConf2Id,
            'is_confirmed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Crear Cría
        [$terneraCatId, $terneraSubId] = $getCategoryIds('ternera');

        $cria2Id = DB::table('caravans')->insertGetId([
            'company_id' => $companyId,
            'batch_id' => $batchId,
            'identification' => 'CRIA-NURS-02',
            'category_id' => $terneraCatId,
            'subcategory_id' => $terneraSubId,
            'breed_id' => $breedId,
            'sex' => 'H',
            'teeth' => 0,
            'entry_weight' => 35.0,
            'entry_date' => now()->subMonths(1),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Registrar parentesco (pedigree)
        DB::table('caravan_lineage')->insert([
            'caravan_id' => $cria2Id,
            'mother_id' => $vacaNurs2Id,
            'father_id' => $toroConf2Id,
            'gestation_id' => $gestation2Id,
            'birth_date' => now()->subMonths(1)->format('Y-m-d'),
            'is_nursing' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);


        // --- ESCENARIO 3: Pérdida Gestacional Registrada ---
        // 1. Crear Madre
        $vacaLoss3Id = DB::table('caravans')->insertGetId([
            'company_id' => $companyId,
            'batch_id' => $batchId,
            'identification' => 'VACA-LOSS-03',
            'category_id' => $vacaCatId,
            'subcategory_id' => $vacaSubId,
            'breed_id' => $breedId,
            'sex' => 'H',
            'teeth' => 5,
            'entry_weight' => 415.0,
            'entry_date' => now()->subMonths(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Detalles reproductivos: vacía (tras la pérdida)
        DB::table('female_caravan_details')->insert([
            'caravan_id' => $vacaLoss3Id,
            'is_empty' => true,
            'arrival_category' => 'vaca',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Obtener el motivo de pérdida "ABORTION"
        $lossReasonId = DB::table('gestation_loss_reasons')
            ->where('company_id', $companyId)
            ->where('code', 'ABORTION')
            ->value('id');

        // Gestación cerrada fallida (pérdida)
        DB::table('caravan_gestations')->insert([
            'caravan_id' => $vacaLoss3Id,
            'start_date' => now()->subMonths(5)->format('Y-m-d'),
            'estimated_due_date' => now()->addMonths(4)->format('Y-m-d'),
            'is_current' => false,
            'success' => false,
            'end_date' => now()->subMonths(2)->format('Y-m-d'),
            'loss_reason_id' => $lossReasonId,
            'loss_notes' => 'Aborto espontáneo detectado en inspección de campo.',
            'gestation_stage' => 'body',
            'gestation_months' => 3.0,
            'notes' => 'Pérdida gestacional registrada.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed specific Service Order from PDF Planilla_De_REP-01_SO-20260605-105950-7238_V4.pdf
        if ($serviceOrderData) {
            $userId = DB::table('users')->first()?->id;

            // Find or create a bull for the Service Order
            $bullId = DB::table('caravans')
                ->where('company_id', $serviceOrderData['company_id'])
                ->where('category_id', $toroCatId)
                ->value('id');

            if (!$bullId) {
                $bullId = DB::table('caravans')->insertGetId([
                    'company_id' => $serviceOrderData['company_id'],
                    'batch_id' => $serviceOrderData['batch_id'],
                    'identification' => 'TORO-REP-01',
                    'category_id' => $toroCatId,
                    'subcategory_id' => $toroSubId,
                    'breed_id' => !empty($breedIds) ? $breedIds[0] : null,
                    'sex' => 'M',
                    'teeth' => 4,
                    'entry_weight' => 550.0,
                    'entry_date' => now()->subMonths(6),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $serviceOrderId = DB::table('service_orders')->insertGetId([
                'company_id' => $serviceOrderData['company_id'],
                'batch_id' => $serviceOrderData['batch_id'],
                'code' => 'SO-20260605-105950-7238',
                'status' => 'APPROVED',
                'service_type' => 'single',
                'is_controlled_service' => false,
                'requested_by_user_id' => $userId,
                'planned_start_date' => now()->subDays(30)->format('Y-m-d'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Associate the females to the Service Order
            foreach ($serviceOrderData['female_ids'] as $femaleId) {
                DB::table('service_order_females')->insert([
                    'company_id' => $serviceOrderData['company_id'],
                    'service_order_id' => $serviceOrderId,
                    'female_caravan_id' => $femaleId,
                    'assigned_male_caravan_id' => $bullId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Associate the male/sire to the Service Order
            DB::table('service_order_males')->insert([
                'company_id' => $serviceOrderData['company_id'],
                'service_order_id' => $serviceOrderId,
                'male_caravan_id' => $bullId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
