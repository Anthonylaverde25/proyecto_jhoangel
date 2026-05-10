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
        DB::table('caravans')->delete();
        DB::table('batches')->delete();
        DB::table('farms')->delete();
        DB::table('providers')->delete();

        // Obtener razas disponibles
        $breedIds = DB::table('breeds')->pluck('id')->toArray();
        $categories = ['novillito', 'novillo', 'vaquillona', 'vaca', 'vaca_vacia', 'ternero', 'toro'];
        
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

        // 2. Crear Granjas (2 por proveedor)
        foreach ($providerIds as $index => $providerId) {
            $farm1Id = DB::table('farms')->insertGetId([
                'name' => 'Sección A - ' . $names[$index],
                'location' => 'Norte del establecimiento',
                'renspa' => '01.0' . ($index + 1) . '.0.00001/01',
                'provider_id' => $providerId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $farm2Id = DB::table('farms')->insertGetId([
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
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $batch2Id = DB::table('batches')->insertGetId([
                    'company_id' => $companyId,
                    'name' => 'Lote Recría - ' . $farmId,
                    'farm_id' => $farmId,
                    'observaciones' => 'Animales en fase de recría.',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $batchIds = [$batch1Id, $batch2Id];

                // 4. Crear Caravanas (5 por lote)
                foreach ($batchIds as $batchId) {
                    for ($i = 1; $i <= 5; $i++) {
                        DB::table('caravans')->insert([
                            'company_id' => $companyId,
                            'batch_id' => $batchId,
                            'identification' => 'CAR-' . $batchId . '-' . $i . '-' . rand(100, 999),
                            'category' => $categories[array_rand($categories)],
                            'breed_id' => !empty($breedIds) ? $breedIds[array_rand($breedIds)] : null,
                            'sex' => rand(0, 1) ? 'M' : 'H',
                            'teeth' => rand(0, 8),
                            'entry_weight' => rand(150, 450) + (rand(0, 99) / 100),
                            'entry_date' => now()->subDays(rand(1, 365)),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }
    }
}
