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
        // Limpiar tablas para permitir re-ejecución (idempotencia)
        DB::table('batches')->delete();
        DB::table('farms')->delete();
        DB::table('providers')->delete();

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
                DB::table('batches')->insert([
                    [
                        'name' => 'Lote Invierno - ' . $farmId,
                        'farm_id' => $farmId,
                        'observaciones' => 'Lote destinado a invernada.',
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'name' => 'Lote Recría - ' . $farmId,
                        'farm_id' => $farmId,
                        'observaciones' => 'Animales en fase de recría.',
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);
            }
        }
    }
}
