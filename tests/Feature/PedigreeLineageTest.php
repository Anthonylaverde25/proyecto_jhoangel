<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\GestationLossReason;
use App\Models\CaravanLineage;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\AnimalCategory;
use App\Application\DTOs\RegisterCaravanDTO;
use App\Application\UseCases\Caravans\UpsertCaravanUseCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PedigreeLineageTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Manually migrate Landlord database (which is :memory: in phpunit.xml)
        Artisan::call('migrate');
        
        // 2. Create Tenant (this automatically triggers tenant database creation and runs tenant migrations)
        $this->tenant = Tenant::create(['id' => 'test-tenant-' . uniqid()]);
        $this->tenant->domains()->create(['domain' => 'test.localhost']);
        
        // 3. Initialize Tenancy context
        tenancy()->initialize($this->tenant);

        // 4. Get the seeded company (created automatically during Tenant::create() seeding hook)
        $this->company = Company::first();
        $this->assertNotNull($this->company);

        // Mock CompanyContext to return our seeded company
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

    public function test_can_register_birth_and_create_lineage_and_close_gestation(): void
    {
        // Get an existing batch from seeder
        $batch = \App\Models\Batch::first();
        $this->assertNotNull($batch);

        // 1. Create a mother with active gestation and a father
        $upsertUseCase = $this->app->make(UpsertCaravanUseCase::class);

        // Create Father
        $fatherDto = new RegisterCaravanDTO(
            identification: 'FATHER-01',
            sex: AnimalSex::MALE,
            category: 'toro',
            teeth: 4,
            entryWeight: 450.0,
            batchId: $batch->id
        );
        $upsertUseCase($fatherDto);
        $father = Caravan::where('identification', 'FATHER-01')->first();

        // Create Mother (pregnant)
        $motherDto = new RegisterCaravanDTO(
            identification: 'MOTHER-01',
            sex: AnimalSex::FEMALE,
            category: 'vaca',
            teeth: 4,
            entryWeight: 380.0,
            isEmpty: false,
            gestationStage: 'body',
            batchId: $batch->id
        );
        $upsertUseCase($motherDto);
        $mother = Caravan::where('identification', 'MOTHER-01')->first();

        // Verify active gestation exists
        $gestation = CaravanGestation::where('caravan_id', $mother->id)->first();
        $this->assertTrue((bool)$gestation->is_current);

        // Link father as potential sire (optional observation in system)
        $gestation->sires()->attach($father->id, ['is_confirmed' => false]);

        // 2. Call bulk-birth endpoint to register birth
        $response = $this->postJson('http://test.localhost/api/caravans/bulk-birth', [
            'births' => [
                [
                    'calf_identification' => 'CALF-01',
                    'calf_sex' => 'H',
                    'calf_category' => 'ternera',
                    'calf_teeth' => 0,
                    'calf_weight' => 45.5,
                    'birth_date' => '2026-05-23',
                    'batch_id' => $batch->id,
                    'mother_id' => $mother->id,
                    'father_id' => $father->id,
                    'gestation_id' => $gestation->id
                ]
            ]
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('0.identification', 'CALF-01');

        // 3. Verify calf was created in DB
        $calf = Caravan::where('identification', 'CALF-01')->first();
        $this->assertNotNull($calf);

        // 4. Verify gestation was closed as successful
        $gestationUpdated = CaravanGestation::find($gestation->id);
        $this->assertFalse((bool)$gestationUpdated->is_current);
        $this->assertTrue((bool)$gestationUpdated->success);
        $this->assertEquals('2026-05-23', $gestationUpdated->end_date->format('Y-m-d'));

        // 5. Verify father is now confirmed sire in pivot
        $confirmedSire = $gestationUpdated->sires()->where('sire_id', $father->id)->first();
        $this->assertNotNull($confirmedSire);
        $this->assertTrue((bool)$confirmedSire->pivot->is_confirmed);

        // 6. Verify lineage was created
        $lineage = CaravanLineage::where('caravan_id', $calf->id)->first();
        $this->assertNotNull($lineage);
        $this->assertEquals($mother->id, $lineage->mother_id);
        $this->assertEquals($father->id, $lineage->father_id);
        $this->assertEquals($gestation->id, $lineage->gestation_id);
        $this->assertTrue($lineage->is_nursing);

        // 7. Verify mother is now marked as empty
        $mother->refresh();
        $this->assertTrue($mother->femaleDetail->is_empty);
    }

    public function test_can_register_gestation_loss(): void
    {
        // 1. Create Mother (pregnant)
        $upsertUseCase = $this->app->make(UpsertCaravanUseCase::class);
        $motherDto = new RegisterCaravanDTO(
            identification: 'MOTHER-02',
            sex: AnimalSex::FEMALE,
            category: 'vaca',
            teeth: 4,
            entryWeight: 380.0,
            isEmpty: false,
            gestationStage: 'body'
        );
        $upsertUseCase($motherDto);
        $mother = Caravan::where('identification', 'MOTHER-02')->first();
        $gestation = CaravanGestation::where('caravan_id', $mother->id)->first();

        // Find loss reason "ABORTION"
        $lossReason = GestationLossReason::where('code', 'ABORTION')->first();
        $this->assertNotNull($lossReason);

        // 2. Call gestation-loss endpoint
        $response = $this->postJson("http://test.localhost/api/caravans/{$mother->id}/gestation-loss", [
            'loss_reason_id' => $lossReason->id,
            'loss_notes' => 'Early spontaneous abortion.',
            'loss_date' => '2026-05-23'
        ]);

        $response->assertStatus(200);

        // 3. Verify gestation closed as failed
        $gestationUpdated = CaravanGestation::find($gestation->id);
        $this->assertFalse((bool)$gestationUpdated->is_current);
        $this->assertFalse((bool)$gestationUpdated->success);
        $this->assertEquals($lossReason->id, $gestationUpdated->loss_reason_id);
        $this->assertEquals('Early spontaneous abortion.', $gestationUpdated->loss_notes);

        // 4. Verify mother is empty
        $mother->refresh();
        $this->assertTrue($mother->femaleDetail->is_empty);
    }

    public function test_can_wean_calf(): void
    {
        $batch = \App\Models\Batch::first();
        $this->assertNotNull($batch);

        // 1. Setup mother and calf with lineage
        $mother = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'MOTHER-03',
            'sex' => AnimalSex::FEMALE,
            'category' => AnimalCategory::VAQUILLONA,
            'teeth' => 4,
        ]);

        $calf = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'CALF-03',
            'sex' => AnimalSex::MALE,
            'category' => AnimalCategory::TERNERO,
            'teeth' => 0,
        ]);

        $lineage = CaravanLineage::create([
            'caravan_id' => $calf->id,
            'mother_id' => $mother->id,
            'birth_date' => '2026-05-01',
            'is_nursing' => true,
        ]);

        // 2. Call wean endpoint
        $response = $this->patchJson("http://test.localhost/api/caravans/{$calf->id}/wean", [
            'target_batch_id' => $batch->id,
            'weaning_date' => '2026-05-30',
            'weaning_weight' => 180.5,
            'new_category' => 'novillito',
            'notes' => 'Weaning in test'
        ]);

        $response->assertStatus(204);

        // 3. Verify calf is weaned
        $lineage->refresh();
        $this->assertFalse($lineage->is_nursing);

        // 4. Verify batch and category changed
        $calf->refresh();
        $this->assertEquals($batch->id, $calf->batch_id);
        $this->assertEquals(AnimalCategory::NOVILLITO, $calf->category);

        // 5. Verify weight is recorded in caravan_weights
        $weight = \App\Models\CaravanWeight::where('caravan_id', $calf->id)->first();
        $this->assertNotNull($weight);
        $this->assertEquals(180.5, $weight->weight);
        $this->assertTrue((bool)$weight->current);

        // 6. Verify movement is recorded in caravan_movements
        $movement = \App\Models\CaravanMovement::where('caravan_id', $calf->id)->first();
        $this->assertNotNull($movement);
        $this->assertEquals('WEANING', $movement->type);
        $this->assertEquals('Weaning in test', $movement->observations);

        // 7. Subsequent weaning call should fail
        $responseDuplicate = $this->patchJson("http://test.localhost/api/caravans/{$calf->id}/wean", [
            'target_batch_id' => $batch->id,
            'weaning_date' => '2026-05-30',
            'weaning_weight' => 180.5
        ]);
        $responseDuplicate->assertStatus(500); // Because we throw a DomainException from Use Case
    }

    public function test_can_bulk_wean_calves(): void
    {
        $batch = \App\Models\Batch::first();
        $this->assertNotNull($batch);

        // Setup two calves with lineages
        $mother = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'MOTHER-BULK',
            'sex' => AnimalSex::FEMALE,
            'category' => AnimalCategory::VACA,
            'teeth' => 6,
        ]);

        $calf1 = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'CALF-BULK-1',
            'sex' => AnimalSex::MALE,
            'category' => AnimalCategory::TERNERO,
            'teeth' => 0,
        ]);

        $calf2 = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'CALF-BULK-2',
            'sex' => AnimalSex::FEMALE,
            'category' => AnimalCategory::TERNERA,
            'teeth' => 0,
        ]);

        $lineage1 = CaravanLineage::create([
            'caravan_id' => $calf1->id,
            'mother_id' => $mother->id,
            'birth_date' => '2026-05-01',
            'is_nursing' => true,
        ]);

        $lineage2 = CaravanLineage::create([
            'caravan_id' => $calf2->id,
            'mother_id' => $mother->id,
            'birth_date' => '2026-05-01',
            'is_nursing' => true,
        ]);

        // Call bulk-wean endpoint
        $response = $this->postJson("http://test.localhost/api/caravans/bulk-wean", [
            'weanings' => [
                [
                    'caravan_id' => $calf1->id,
                    'target_batch_id' => $batch->id,
                    'weaning_date' => '2026-05-30',
                    'weaning_weight' => 190.0,
                    'new_category' => 'novillito',
                    'notes' => 'Bulk weaning calf 1'
                ],
                [
                    'caravan_id' => $calf2->id,
                    'target_batch_id' => $batch->id,
                    'weaning_date' => '2026-05-30',
                    'weaning_weight' => 175.0,
                    'new_category' => 'vaquillona',
                    'notes' => 'Bulk weaning calf 2'
                ]
            ]
        ]);

        $response->assertStatus(204);

        // Verify both are weaned
        $lineage1->refresh();
        $lineage2->refresh();
        $this->assertFalse($lineage1->is_nursing);
        $this->assertFalse($lineage2->is_nursing);

        // Verify batch and category changed
        $calf1->refresh();
        $calf2->refresh();
        $this->assertEquals($batch->id, $calf1->batch_id);
        $this->assertEquals(AnimalCategory::NOVILLITO, $calf1->category);
        $this->assertEquals($batch->id, $calf2->batch_id);
        $this->assertEquals(AnimalCategory::VAQUILLONA, $calf2->category);

        // Verify weights registered
        $weight1 = \App\Models\CaravanWeight::where('caravan_id', $calf1->id)->first();
        $weight2 = \App\Models\CaravanWeight::where('caravan_id', $calf2->id)->first();
        $this->assertNotNull($weight1);
        $this->assertEquals(190.0, $weight1->weight);
        $this->assertNotNull($weight2);
        $this->assertEquals(175.0, $weight2->weight);
    }

    public function test_can_register_birth_inheriting_sire_from_gestation(): void
    {
        $batch = \App\Models\Batch::first();
        $this->assertNotNull($batch);

        $upsertUseCase = $this->app->make(UpsertCaravanUseCase::class);

        // Create Father
        $fatherDto = new RegisterCaravanDTO(
            identification: 'FATHER-INHERIT',
            sex: AnimalSex::MALE,
            category: 'toro',
            teeth: 4,
            entryWeight: 450.0,
            batchId: $batch->id
        );
        $upsertUseCase($fatherDto);
        $father = Caravan::where('identification', 'FATHER-INHERIT')->first();

        // Create Mother (pregnant)
        $motherDto = new RegisterCaravanDTO(
            identification: 'MOTHER-INHERIT',
            sex: AnimalSex::FEMALE,
            category: 'vaca',
            teeth: 4,
            entryWeight: 380.0,
            isEmpty: false,
            gestationStage: 'body',
            batchId: $batch->id
        );
        $upsertUseCase($motherDto);
        $mother = Caravan::where('identification', 'MOTHER-INHERIT')->first();

        // Get gestation and link father as sire (unconfirmed)
        $gestation = CaravanGestation::where('caravan_id', $mother->id)->first();
        $gestation->sires()->attach($father->id, ['is_confirmed' => false]);

        // Call bulk-birth without father_id in request
        $response = $this->postJson('http://test.localhost/api/caravans/bulk-birth', [
            'births' => [
                [
                    'calf_identification' => 'CALF-INHERIT',
                    'calf_sex' => 'H',
                    'calf_category' => 'ternera',
                    'calf_teeth' => 0,
                    'calf_weight' => 42.0,
                    'birth_date' => '2026-05-23',
                    'batch_id' => $batch->id,
                    'mother_id' => $mother->id,
                    'father_id' => null,
                    'gestation_id' => $gestation->id
                ]
            ]
        ]);

        $response->assertStatus(201);

        // Verify calf was created with lineage inheriting the father
        $calf = Caravan::where('identification', 'CALF-INHERIT')->first();
        $this->assertNotNull($calf);

        $lineage = CaravanLineage::where('caravan_id', $calf->id)->first();
        $this->assertNotNull($lineage);
        $this->assertEquals($father->id, $lineage->father_id);

        // Verify sire is now confirmed in the closed gestation
        $confirmedSire = $gestation->sires()->where('sire_id', $father->id)->first();
        $this->assertNotNull($confirmedSire);
        $this->assertTrue((bool)$confirmedSire->pivot->is_confirmed);
    }

    public function test_can_register_birth_with_null_father(): void
    {
        $batch = \App\Models\Batch::first();
        $this->assertNotNull($batch);

        $upsertUseCase = $this->app->make(UpsertCaravanUseCase::class);

        // Create Mother (pregnant) without sires
        $motherDto = new RegisterCaravanDTO(
            identification: 'MOTHER-PREGNANT-BUY',
            sex: AnimalSex::FEMALE,
            category: 'vaca',
            teeth: 4,
            entryWeight: 380.0,
            isEmpty: false,
            gestationStage: 'body',
            batchId: $batch->id
        );
        $upsertUseCase($motherDto);
        $mother = Caravan::where('identification', 'MOTHER-PREGNANT-BUY')->first();
        $gestation = CaravanGestation::where('caravan_id', $mother->id)->first();

        // Call bulk-birth without father_id in request
        $response = $this->postJson('http://test.localhost/api/caravans/bulk-birth', [
            'births' => [
                [
                    'calf_identification' => 'CALF-NULL-FATHER',
                    'calf_sex' => 'M',
                    'calf_category' => 'ternero',
                    'calf_teeth' => 0,
                    'calf_weight' => 40.0,
                    'birth_date' => '2026-05-23',
                    'batch_id' => $batch->id,
                    'mother_id' => $mother->id,
                    'father_id' => null,
                    'gestation_id' => $gestation->id
                ]
            ]
        ]);

        $response->assertStatus(201);

        $calf = Caravan::where('identification', 'CALF-NULL-FATHER')->first();
        $this->assertNotNull($calf);

        // Verify lineage was created but father is null
        $lineage = CaravanLineage::where('caravan_id', $calf->id)->first();
        $this->assertNotNull($lineage);
        $this->assertNull($lineage->father_id);
    }

    public function test_can_wean_calf_to_own_batch_resolving_company_renspa(): void
    {
        // 1. Create own batch (farm_id = null)
        $batchType = \App\Models\BatchType::where('code', 'OPERATIONAL')->first();
        $ownBatch = \App\Models\Batch::create([
            'company_id' => $this->company->id,
            'name' => 'OWN-BATCH-TEST',
            'farm_id' => null,
            'batch_type_id' => $batchType->id,
        ]);

        // 2. Setup mother and calf with lineage
        $mother = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'MOTHER-OWN',
            'sex' => AnimalSex::FEMALE,
            'category' => AnimalCategory::VAQUILLONA,
            'teeth' => 4,
        ]);

        $calf = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'CALF-OWN',
            'sex' => AnimalSex::MALE,
            'category' => AnimalCategory::TERNERO,
            'teeth' => 0,
        ]);

        $lineage = CaravanLineage::create([
            'caravan_id' => $calf->id,
            'mother_id' => $mother->id,
            'birth_date' => '2026-05-01',
            'is_nursing' => true,
        ]);

        // 3. Call wean endpoint targeting the own batch
        $response = $this->patchJson("http://test.localhost/api/caravans/{$calf->id}/wean", [
            'target_batch_id' => $ownBatch->id,
            'weaning_date' => '2026-05-30',
            'weaning_weight' => 195.0,
            'new_category' => 'novillito',
            'notes' => 'Weaned to own batch'
        ]);

        $response->assertStatus(204);

        // 4. Verify batch and category changed
        $calf->refresh();
        $this->assertEquals($ownBatch->id, $calf->batch_id);

        // 5. Verify movement is recorded with company's renspa
        $movement = \App\Models\CaravanMovement::where('caravan_id', $calf->id)
            ->where('type', 'WEANING')
            ->first();
        $this->assertNotNull($movement);
        $this->assertEquals($this->company->renspa, $movement->renspa);
    }

    public function test_service_order_transfer_to_own_batch_resolves_company_renspa(): void
    {
        // 1. Create own batch
        $batchType = \App\Models\BatchType::where('code', 'OPERATIONAL')->first();
        $ownBatch = \App\Models\Batch::create([
            'company_id' => $this->company->id,
            'name' => 'OWN-BATCH-SO',
            'farm_id' => null,
            'batch_type_id' => $batchType->id,
        ]);

        $calf = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'CALF-SO',
            'sex' => AnimalSex::MALE,
            'category' => AnimalCategory::NOVILLITO,
            'teeth' => 2,
        ]);

        // 2. Call moveAnimalsToBatch directly in EloquentServiceOrderRepository
        $repo = $this->app->make(\App\Infrastructure\Persistence\EloquentServiceOrderRepository::class);
        $repo->moveAnimalsToBatch([$calf->id], $ownBatch->id, $this->company->id, 1);

        // 3. Verify movement created with company's renspa
        $movement = \App\Models\CaravanMovement::where('caravan_id', $calf->id)
            ->where('type', 'TRANSFER')
            ->first();
        $this->assertNotNull($movement);
        $this->assertEquals($this->company->renspa, $movement->renspa);
    }

    public function test_can_get_single_caravan_pedigree_and_inbreeding_breakdown(): void
    {
        // 1. Create Sire and Dam with lineage
        $sire = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'SIRE-TEST-01',
            'sex' => AnimalSex::MALE,
            'category' => AnimalCategory::TORO,
            'teeth' => 8,
        ]);

        $dam = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'DAM-TEST-01',
            'sex' => AnimalSex::FEMALE,
            'category' => AnimalCategory::VACA,
            'teeth' => 6,
        ]);

        $calf = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'CALF-TEST-01',
            'sex' => AnimalSex::MALE,
            'category' => AnimalCategory::TERNERO,
            'teeth' => 0,
        ]);

        CaravanLineage::create([
            'caravan_id' => $calf->id,
            'father_id' => $sire->id,
            'mother_id' => $dam->id,
            'birth_date' => '2026-06-01',
            'is_nursing' => false,
        ]);

        // 2. Call GET /caravans/{id}/pedigree
        $response = $this->getJson("http://test.localhost/api/caravans/{$calf->id}/pedigree");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'caravan' => ['id', 'identification', 'category', 'sex'],
            'inbreeding' => ['fx', 'risk', 'risk_label', 'is_exogamous', 'common_ancestors', 'zootechnical_verdict'],
            'tree' => ['father', 'mother', 'pgs', 'pgd', 'mgs', 'mgd', 'pps', 'ppd', 'pms', 'pmd', 'mps', 'mpd', 'mms', 'mmd'],
            'offspring',
        ]);

        $this->assertEquals('CALF-TEST-01', $response->json('caravan.identification'));
        $this->assertEquals('SIRE-TEST-01', $response->json('tree.father.identification'));
        $this->assertEquals('DAM-TEST-01', $response->json('tree.mother.identification'));
    }
}



