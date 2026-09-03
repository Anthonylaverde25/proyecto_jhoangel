<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Enums\AnimalCategory;
use App\Core\Enums\AnimalSex;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\Company;
use App\Models\Farm;
use App\Models\Provider;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;


class AssignExternalCaravansToOwnBatchTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->tenant = Tenant::create(['id' => 'test-tenant-' . uniqid()]);
        $this->tenant->domains()->create(['domain' => 'test.localhost']);
        tenancy()->initialize($this->tenant);

        $this->company = Company::first();
        $this->assertNotNull($this->company);

        $companyContext = new \App\Core\Contexts\CompanyContext();
        $companyContext->setCompanyId($this->company->id);
        $this->app->instance(\App\Core\Interfaces\ICompanyContext::class, $companyContext);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();
        Artisan::call('migrate:rollback');

        parent::tearDown();
    }

    public function test_can_assign_caravans_from_external_batch_to_own_batch_and_preserve_provenance(): void
    {
        // 1. Crear Proveedor y Granja Externa
        $provider = Provider::create([
            'name' => 'Cabaña San Pedro',
            'cuit' => '30-99887766-5',
            'is_active' => true
        ]);

        $externalFarm = Farm::create([
            'name' => 'Establecimiento Origen',
            'renspa' => '01.002.3.00456/00',
            'provider_id' => $provider->id,
            'is_active' => true
        ]);

        // 2. Crear Lote Externo
        $externalBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote 14 Remate Anual',
            'farm_id' => $externalFarm->id,
            'is_active' => true
        ]);

        // 3. Crear Lote Propio (farm_id null o propio)
        $ownBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Invernada Corral 1',
            'farm_id' => null,
            'is_active' => true
        ]);


        // 4. Crear Caravanas en Lote Externo
        $caravan1 = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $externalBatch->id,
            'provider_id' => $provider->id,
            'renspa' => '01.002.3.00456/00',
            'identification' => '5001',
            'teeth' => 2,
            'sex' => AnimalSex::FEMALE,
            'entry_weight' => 380.0
        ]);

        $caravan2 = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $externalBatch->id,
            'provider_id' => $provider->id,
            'renspa' => '01.002.3.00456/00',
            'identification' => '5002',
            'teeth' => 2,
            'sex' => AnimalSex::FEMALE,
            'entry_weight' => 390.0
        ]);


        // 5. Ejecutar Endpoint de Asignación
        $response = $this->postJson('http://test.localhost/api/batches/assign-to-own', [
            'caravan_ids' => [$caravan1->id, $caravan2->id],
            'target_batch_id' => $ownBatch->id,
            'entry_date' => '2026-08-15',
            'observations' => 'Ingreso por compra de remate'
        ], [
            'X-Company-ID' => (string) $this->company->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.transferred', 2);

        // 6. Validar Base de Datos: renspa se actualiza al lote propio, origin_renspa queda en metadata
        $caravan1->refresh();
        $this->assertEquals($ownBatch->id, $caravan1->batch_id);
        $this->assertEquals('NO_DEFINIDO', $caravan1->renspa);
        $this->assertEquals('01.002.3.00456/00', $caravan1->provenance_metadata['origin_renspa']);
        $this->assertEquals('Lote 14 Remate Anual', $caravan1->provenance_metadata['origin_batch_name']);

        $this->assertDatabaseHas('caravan_movements', [
            'caravan_id' => $caravan1->id,
            'from_batch_id' => $externalBatch->id,
            'to_batch_id' => $ownBatch->id,
            'type' => 'PURCHASE',
            'renspa' => 'NO_DEFINIDO',
            'from_renspa' => '01.002.3.00456/00'
        ]);
    }

    public function test_cannot_assign_caravans_that_are_already_in_own_batch(): void
    {
        // 1. Crear un Lote Propio Origen (farm_id = null)
        $ownBatchSource = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Propio de Origen',
            'farm_id' => null,
            'is_active' => true
        ]);

        // 2. Crear un Lote Propio Destino (farm_id = null)
        $ownBatchTarget = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Propio de Destino',
            'farm_id' => null,
            'is_active' => true
        ]);

        // 3. Crear Caravana en Lote Propio Origen
        $caravan = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $ownBatchSource->id,
            'renspa' => 'NO_DEFINIDO',
            'identification' => '6001',
            'teeth' => 2,
            'sex' => AnimalSex::FEMALE,
            'entry_weight' => 380.0
        ]);

        // 4. Ejecutar Endpoint de Asignación, intentando moverla al lote propio destino
        $response = $this->postJson('http://test.localhost/api/batches/assign-to-own', [
            'caravan_ids' => [$caravan->id],
            'target_batch_id' => $ownBatchTarget->id,
            'entry_date' => '2026-08-15',
            'observations' => 'Intento de asignación inválido'
        ], [
            'X-Company-ID' => (string) $this->company->id,
        ]);

        // 5. Asertar que falla con 500 (DomainException)
        $response->assertStatus(500);
        $this->assertStringContainsString('ya pertenece a un lote propio', $response->json('message'));
    }
}

