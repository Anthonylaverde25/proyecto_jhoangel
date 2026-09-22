<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Company;
use App\Models\CompanyBatchType;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BatchTypeMigrationTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->tenant = Tenant::create(['id' => 'test-tenant-' . uniqid()]);
        $this->tenant->domains()->create(['domain' => 'test.localhost']);
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();

        parent::tearDown();
    }

    public function test_batch_types_have_unique_code_in_tenant(): void
    {
        // BatchTypeSeeder already seeded canonical types in the tenant.
        // Attempting to create another BatchType with the same code must fail
        $this->expectException(QueryException::class);

        BatchType::create([
            'name' => 'Operational Duplicate',
            'code' => 'OPERATIONAL',
            'description' => 'Duplicate code should fail',
            'is_active' => true,
        ]);
    }

    public function test_multiple_companies_can_attach_to_the_same_batch_type(): void
    {
        $companyA = Company::create([
            'name' => 'Company A',
            'renspa' => '123456789012',
            'location' => 'Location A',
            'is_active' => true,
        ]);

        $companyB = Company::create([
            'name' => 'Company B',
            'renspa' => '210987654321',
            'location' => 'Location B',
            'is_active' => true,
        ]);

        $canonicalType = BatchType::where('code', 'WEANING')->first();
        $this->assertNotNull($canonicalType);

        CompanyBatchType::firstOrCreate([
            'company_id' => $companyA->id,
            'batch_type_id' => $canonicalType->id,
        ], ['is_enabled' => true]);

        CompanyBatchType::firstOrCreate([
            'company_id' => $companyB->id,
            'batch_type_id' => $canonicalType->id,
        ], ['is_enabled' => true]);

        $this->assertTrue($companyA->batchTypes->contains('id', $canonicalType->id));
        $this->assertTrue($companyB->batchTypes->contains('id', $canonicalType->id));
    }

    public function test_company_cannot_attach_duplicate_batch_type(): void
    {
        $company = Company::create([
            'name' => 'Test Company',
            'renspa' => '123456789012',
            'location' => 'Test Location',
            'is_active' => true,
        ]);

        $batchType = BatchType::where('code', 'SERVICE')->first();
        $this->assertNotNull($batchType);

        CompanyBatchType::create([
            'company_id' => $company->id,
            'batch_type_id' => $batchType->id,
            'is_enabled' => true,
        ]);

        $this->expectException(QueryException::class);

        // Duplicate attach should fail due to unique constraint [company_id, batch_type_id]
        CompanyBatchType::create([
            'company_id' => $company->id,
            'batch_type_id' => $batchType->id,
            'is_enabled' => true,
        ]);
    }
}
