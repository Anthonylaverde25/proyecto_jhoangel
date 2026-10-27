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

        $this->registerDevHosts(Tenant::find($tenantId));
    }

    /**
     * Extra hosts tenancy must resolve to the dev tenant, from the environment (unset: nothing):
     * - DEV_LAN_HOST (e.g. 192.168.1.50): a physical phone reaches the API through the Mac's LAN address.
     * - DEV_MCP_HOST (e.g. the fixed ngrok domain): claude.ai / Gemini reach the MCP OAuth endpoints
     *   through the public tunnel, and Laravel resolves the tenant from that Host.
     * Runs on an existing tenant too, so a new host does not need a migrate:fresh.
     */
    private function registerDevHosts(?Tenant $tenant): void
    {
        if ($tenant === null) {
            return;
        }

        foreach (['DEV_LAN_HOST' => 'red local', 'DEV_MCP_HOST' => 'MCP público'] as $variable => $label) {
            $host = trim((string) env($variable, ''));
            if ($host === '') {
                continue;
            }

            $tenant->domains()->firstOrCreate(['domain' => $host]);
            $this->command->info("Landlord: dominio de {$label} {$host} asignado al tenant {$tenant->id}");
        }
    }
}
