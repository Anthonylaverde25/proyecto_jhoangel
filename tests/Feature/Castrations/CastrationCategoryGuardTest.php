<?php

declare(strict_types=1);

namespace Tests\Feature\Castrations;

use App\Application\DTOs\RegisterCaravanDTO;
use App\Application\DTOs\WeanCaravanDTO;
use App\Application\UseCases\Caravans\UpsertCaravanUseCase;
use App\Application\UseCases\Caravans\WeanCaravanUseCase;
use App\Core\Enums\AnimalSex;
use App\Core\Exceptions\DomainException;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanCastration;
use App\Models\CaravanLineage;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CastrationCategoryGuardTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;
    private Batch $batch;
    private int $terneroCatId;
    private int $toritoCatId;
    private int $novillitoCatId;

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

        $this->batch = Batch::first();

        $categories = AnimalCategory::pluck('id', 'code');
        $this->terneroCatId = (int) $categories['TERNERO'];
        $this->toritoCatId = (int) $categories['TORITO'];
        $this->novillitoCatId = (int) $categories['NOVILLITO'];
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    public function test_wean_guard_refuses_torito_for_castrated_calf_but_allows_novillito(): void
    {
        $mother = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'MOTHER-WEAN-1',
            'sex' => 'H',
            'teeth' => 4,
        ]);

        $calf = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'CALF-WEAN-GUARD',
            'sex' => 'M',
            'category_id' => $this->terneroCatId,
            'teeth' => 0,
        ]);

        CaravanLineage::create([
            'caravan_id' => $calf->id,
            'mother_id' => $mother->id,
            'is_nursing' => true,
            'birth_date' => '2026-01-01',
        ]);

        CaravanCastration::create([
            'company_id' => $this->company->id,
            'caravan_id' => $calf->id,
            'castrated_on' => '2026-10-01',
            'from_category_id' => $this->terneroCatId,
        ]);

        $useCase = app(WeanCaravanUseCase::class);

        // Attempting to wean into TORITO must be refused
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/está castrado: no puede pasar a Torito/i');

        $useCase(new WeanCaravanDTO(
            caravanId: (int) $calf->id,
            targetBatchId: (int) $this->batch->id,
            weaningDate: '2026-10-30',
            weaningWeight: null,
            newCategoryId: $this->toritoCatId,
            weaningType: \App\Core\Enums\WeaningType::TRADITIONAL
        ));
    }

    public function test_wean_guard_allows_novillito_for_castrated_calf(): void
    {
        $mother = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'MOTHER-WEAN-2',
            'sex' => 'H',
            'teeth' => 4,
        ]);

        $calf = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'CALF-WEAN-NOV',
            'sex' => 'M',
            'category_id' => $this->terneroCatId,
            'teeth' => 0,
        ]);

        CaravanLineage::create([
            'caravan_id' => $calf->id,
            'mother_id' => $mother->id,
            'is_nursing' => true,
            'birth_date' => '2026-01-01',
        ]);

        CaravanCastration::create([
            'company_id' => $this->company->id,
            'caravan_id' => $calf->id,
            'castrated_on' => '2026-10-01',
            'from_category_id' => $this->terneroCatId,
        ]);

        $useCase = app(WeanCaravanUseCase::class);

        $movementId = $useCase(new WeanCaravanDTO(
            caravanId: (int) $calf->id,
            targetBatchId: (int) $this->batch->id,
            weaningDate: '2026-10-30',
            weaningWeight: null,
            newCategoryId: $this->novillitoCatId,
            weaningType: \App\Core\Enums\WeaningType::TRADITIONAL
        ));

        $this->assertIsInt($movementId);
        $calf->refresh();
        $this->assertSame($this->novillitoCatId, (int) $calf->category_id);
    }

    public function test_upsert_guard_refuses_torito_for_castrated_calf(): void
    {
        $calf = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'CALF-EDIT-GUARD',
            'sex' => 'M',
            'category_id' => $this->terneroCatId,
            'teeth' => 0,
        ]);

        CaravanCastration::create([
            'company_id' => $this->company->id,
            'caravan_id' => $calf->id,
            'castrated_on' => '2026-10-01',
            'from_category_id' => $this->terneroCatId,
        ]);

        $upsert = app(UpsertCaravanUseCase::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/está castrado: no puede pasar a Torito/i');

        $upsert(new RegisterCaravanDTO(
            identification: 'CALF-EDIT-GUARD',
            sex: AnimalSex::MALE,
            category: 'TORITO',
            categoryId: $this->toritoCatId
        ));
    }
}
