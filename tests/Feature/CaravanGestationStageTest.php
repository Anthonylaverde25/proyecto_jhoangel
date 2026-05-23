<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\GestationStage;
use App\Application\DTOs\RegisterCaravanDTO;
use App\Application\UseCases\Caravans\UpsertCaravanUseCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CaravanGestationStageTest extends TestCase
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

        // 4. Create Company for tests
        $this->company = Company::create([
            'name' => 'Test Ranch',
            'renspa' => '123456789012',
            'location' => 'Test Location',
            'is_active' => true,
        ]);
        
        // Mock CompanyContext to return our test company
        $companyContext = new \App\Core\Contexts\CompanyContext();
        $companyContext->setCompanyId($this->company->id);
        $this->app->instance(\App\Core\Interfaces\ICompanyContext::class, $companyContext);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        
        // Deleting the tenant triggers database deletion hook
        $this->tenant->delete();
        
        // Reset migrations/tables in central db
        Artisan::call('migrate:rollback');

        parent::tearDown();
    }

    public function test_migration_added_gestation_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('caravan_gestations', 'gestation_stage'));
        $this->assertTrue(Schema::hasColumn('caravan_gestations', 'gestation_months'));
    }

    public function test_can_upsert_pregnant_caravan_with_gestation_stage(): void
    {
        $useCase = $this->app->make(UpsertCaravanUseCase::class);

        $dto = new RegisterCaravanDTO(
            identification: 'CARAVAN-PREG-1',
            sex: AnimalSex::FEMALE,
            category: 'vaquillona',
            teeth: 2,
            entryWeight: 350.0,
            isEmpty: false, // preñada
            gestationStage: 'body'
        );

        $result = $useCase($dto);
        $this->assertEquals('created', $result->action);

        // Verify the Caravan was created
        $caravan = Caravan::where('identification', 'CARAVAN-PREG-1')->first();
        $this->assertNotNull($caravan);

        // Verify the CaravanGestation was created with the correct stage and default months
        $gestation = CaravanGestation::where('caravan_id', $caravan->id)->first();
        $this->assertNotNull($gestation);
        $this->assertEquals(GestationStage::BODY, $gestation->gestation_stage);
        $this->assertEquals(2.0, $gestation->gestation_months);
        $this->assertTrue($gestation->is_current);
    }

    public function test_api_validation_requires_gestation_stage_or_months_when_pregnant(): void
    {
        // Pregnancy (is_empty = false) requires either stage or months
        $response = $this->postJson('http://test.localhost/api/caravans', [
            'identification' => 'CARAVAN-ERR-1',
            'sex' => 'H',
            'category' => 'vaquillona',
            'teeth' => 2,
            'is_empty' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['gestation_stage', 'gestation_months']);
    }

    public function test_api_validation_accepts_valid_gestation_stage(): void
    {
        $response = $this->postJson('http://test.localhost/api/caravans', [
            'identification' => 'CARAVAN-OK-1',
            'sex' => 'H',
            'category' => 'vaquillona',
            'teeth' => 2,
            'is_empty' => false,
            'gestation_stage' => 'tail',
        ]);

        $response->assertStatus(201);
        
        $caravan = Caravan::where('identification', 'CARAVAN-OK-1')->first();
        $this->assertNotNull($caravan);

        $gestation = CaravanGestation::where('caravan_id', $caravan->id)->first();
        $this->assertNotNull($gestation);
        $this->assertEquals(GestationStage::TAIL, $gestation->gestation_stage);
        $this->assertEquals(1.0, $gestation->gestation_months);
    }

    public function test_api_validation_rejects_invalid_gestation_stage(): void
    {
        $response = $this->postJson('http://test.localhost/api/caravans', [
            'identification' => 'CARAVAN-ERR-2',
            'sex' => 'H',
            'category' => 'vaquillona',
            'teeth' => 2,
            'is_empty' => false,
            'gestation_stage' => 'invalid_stage',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['gestation_stage']);
    }

    public function test_api_validation_does_not_require_gestation_stage_when_empty(): void
    {
        // When is_empty = true (vacia), gestation_stage is not required and no gestation is created
        $response = $this->postJson('http://test.localhost/api/caravans', [
            'identification' => 'CARAVAN-EMPTY-1',
            'sex' => 'H',
            'category' => 'vaquillona',
            'teeth' => 2,
            'is_empty' => true,
        ]);

        $response->assertStatus(201);
        
        $caravan = Caravan::where('identification', 'CARAVAN-EMPTY-1')->first();
        $this->assertNotNull($caravan);

        $gestationCount = CaravanGestation::where('caravan_id', $caravan->id)->count();
        $this->assertEquals(0, $gestationCount);
    }

    public function test_can_upsert_pregnant_caravan_with_gestation_months_only(): void
    {
        $response = $this->postJson('http://test.localhost/api/caravans', [
            'identification' => 'CARAVAN-MONTHS-1',
            'sex' => 'H',
            'category' => 'vaquillona',
            'teeth' => 2,
            'is_empty' => false,
            'gestation_months' => 1.5,
        ]);

        $response->assertStatus(201);

        $caravan = Caravan::where('identification', 'CARAVAN-MONTHS-1')->first();
        $this->assertNotNull($caravan);

        $gestation = CaravanGestation::where('caravan_id', $caravan->id)->first();
        $this->assertNotNull($gestation);
        $this->assertEquals(GestationStage::BODY, $gestation->gestation_stage);
        $this->assertEquals(1.5, $gestation->gestation_months);
    }

    public function test_can_upsert_pregnant_caravan_with_gestation_stage_only_and_uses_default_months(): void
    {
        $response = $this->postJson('http://test.localhost/api/caravans', [
            'identification' => 'CARAVAN-STAGE-1',
            'sex' => 'H',
            'category' => 'vaquillona',
            'teeth' => 2,
            'is_empty' => false,
            'gestation_stage' => 'tail',
        ]);

        $response->assertStatus(201);

        $caravan = Caravan::where('identification', 'CARAVAN-STAGE-1')->first();
        $this->assertNotNull($caravan);

        $gestation = CaravanGestation::where('caravan_id', $caravan->id)->first();
        $this->assertNotNull($gestation);
        $this->assertEquals(GestationStage::TAIL, $gestation->gestation_stage);
        $this->assertEquals(1.0, $gestation->gestation_months);
    }

    public function test_updating_pregnant_caravan_to_empty_closes_active_gestation_successfully(): void
    {
        // 1. Create a pregnant caravan via API
        $response = $this->postJson('http://test.localhost/api/caravans', [
            'identification' => 'MOTHER-1',
            'sex' => 'H',
            'category' => 'vaquillona',
            'teeth' => 2,
            'is_empty' => false,
            'gestation_stage' => 'body',
        ]);
        $response->assertStatus(201);

        $caravan = Caravan::where('identification', 'MOTHER-1')->first();
        $this->assertNotNull($caravan);
        $gestation = CaravanGestation::where('caravan_id', $caravan->id)->first();
        $this->assertTrue((bool)$gestation->is_current);
        $this->assertNull($gestation->success);

        // 2. Update the same caravan to empty (is_empty = true)
        $responseUpdate = $this->postJson('http://test.localhost/api/caravans', [
            'identification' => 'MOTHER-1',
            'sex' => 'H',
            'category' => 'vaquillona',
            'teeth' => 2,
            'is_empty' => true,
        ]);
        $responseUpdate->assertStatus(200);

        // 3. Verify the gestation has been closed
        $gestationUpdated = CaravanGestation::where('caravan_id', $caravan->id)->first();
        $this->assertFalse((bool)$gestationUpdated->is_current);
        $this->assertTrue((bool)$gestationUpdated->success);
        $this->assertNotNull($gestationUpdated->end_date);
        $this->assertEquals('Closed via calving registration.', $gestationUpdated->notes);
    }
}
