<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Enums\PartnerRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Who the company buys livestock from, and their origin farms. Own batches carry no farm: the
 * company is its own farm (D21). The rodeo itself is RodeoDemoSeeder.
 */
class LivestockHierarchySeeder extends Seeder
{
    public function run(): void
    {
        // 1. Limpiar tablas para permitir re-ejecución (idempotencia)
        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        } else {
            DB::statement('PRAGMA foreign_keys = OFF;');
        }

        DB::table('service_order_females')->delete();
        DB::table('service_order_males')->delete();
        DB::table('service_orders')->delete();
        DB::table('caravan_gestations')->delete();
        DB::table('female_caravan_details')->delete();
        DB::table('caravan_weights')->delete();
        DB::table('caravans')->delete();
        DB::table('batches')->delete();
        DB::table('farms')->delete();
        DB::table('partner_contacts')->delete();
        DB::table('partner_roles')->delete();
        DB::table('partners')->delete();

        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        } else {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        // Obtener la primera empresa disponible
        $companyId = DB::table('companies')->first()->id;

        // 1. Crear Proveedores
        // Seeders run without model events: company_id goes explicitly (providers are company-scoped).
        $provider1Id = $this->insertLivestockSupplier($companyId, [
            'name' => 'Estancia El Trébol S.A.',
            'commercial_name' => 'El Trébol',
            'cuit' => '30123456781',
            'location' => 'Ruta 5, Km 150, Chivilcoy',
        ], 'administracion@eltrebol.com', '+54 2346 123456');

        $provider2Id = $this->insertLivestockSupplier($companyId, [
            'name' => 'Ganadera del Sur SRL',
            'commercial_name' => 'Ganadera Sur',
            'cuit' => '30987654321',
            'location' => 'Ruta 226, Km 40, Balcarce',
        ], 'ventas@ganaderasur.com', '+54 2266 987654');

        $providerIds = [$provider1Id, $provider2Id];
        $names = ['El Trébol', 'Ganadera Sur'];

        foreach ($providerIds as $index => $providerId) {
            foreach (['A' => 'Norte', 'B' => 'Sur'] as $section => $zone) {
                DB::table('farms')->insert([
                    'company_id' => $companyId,
                    'name' => "Sección {$section} - " . $names[$index],
                    'location' => "{$zone} del establecimiento",
                    'renspa' => '01.0' . ($index + 1) . '.0.0000' . ($section === 'A' ? 1 : 2) . '/01',
                    'provider_id' => $providerId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * A partner that sells livestock, with its contact as the default one (egreso plan v5, A1–A2).
     *
     * @param array<string, string> $attributes
     */
    private function insertLivestockSupplier(int $companyId, array $attributes, string $email, string $phone): int
    {
        $id = DB::table('partners')->insertGetId($attributes + [
            'company_id' => $companyId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('partner_roles')->insert([
            'company_id' => $companyId, 'partner_id' => $id, 'role' => PartnerRole::SUPPLIER_LIVESTOCK->value,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('partner_contacts')->insert([
            'company_id' => $companyId, 'partner_id' => $id, 'name' => 'Principal', 'phone' => $phone, 'email' => $email,
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
