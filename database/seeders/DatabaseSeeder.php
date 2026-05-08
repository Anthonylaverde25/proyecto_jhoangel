<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $tenantId = 'dev_tenant';

        if (!Tenant::find($tenantId)) {
            // Si el tenant no existe en la BD central, pero su BD física sí quedó huérfana de un migrate:fresh anterior, la borramos.
            \Illuminate\Support\Facades\DB::statement("DROP DATABASE IF EXISTS tenant_{$tenantId}");
            
            $tenant = Tenant::create(['id' => $tenantId]);

            // Le asignamos el dominio 'localhost' para trabajar en local
            $tenant->domains()->create(['domain' => 'localhost']);
            
            // También podemos agregar 127.0.0.1 por si acceden con esa IP
            $tenant->domains()->create(['domain' => '127.0.0.1']);

            $this->command->info("Landlord: Tenant creado con éxito ({$tenantId}) en dominios: localhost, 127.0.0.1");
        } else {
            $this->command->info("Landlord: El tenant {$tenantId} ya existe. Omitiendo creación.");
        }
    }
}
