<?php

namespace Tests\Feature;

use App\Models\BatchType;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class BatchTypeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_types_can_have_duplicate_codes_in_different_companies(): void
    {
        // 1. Create a test tenant with unique ID
        $tenantId = 'tenant-' . uniqid();
        $tenant = Tenant::create(['id' => $tenantId]);
        
        try {
            // 2. Initialize tenancy context
            tenancy()->initialize($tenant);

            // 3. Create two distinct companies
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

            // 4. Create BatchType with the same code in different companies (should succeed)
            $batchTypeA = BatchType::create([
                'company_id' => $companyA->id,
                'name' => 'Operational A',
                'code' => 'OPERATIONAL',
                'description' => 'Operational type for A',
                'is_active' => true,
            ]);

            $batchTypeB = BatchType::create([
                'company_id' => $companyB->id,
                'name' => 'Operational B',
                'code' => 'OPERATIONAL',
                'description' => 'Operational type for B',
                'is_active' => true,
            ]);

            $this->assertDatabaseHas('batch_types', [
                'id' => $batchTypeA->id,
                'company_id' => $companyA->id,
                'code' => 'OPERATIONAL',
            ]);

            $this->assertDatabaseHas('batch_types', [
                'id' => $batchTypeB->id,
                'company_id' => $companyB->id,
                'code' => 'OPERATIONAL',
            ]);
        } finally {
            // Clean up database and end tenancy context
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            $tenant->delete();
        }
    }

    public function test_batch_types_cannot_have_duplicate_codes_in_the_same_company(): void
    {
        // 1. Create a test tenant with unique ID
        $tenantId = 'tenant-' . uniqid();
        $tenant = Tenant::create(['id' => $tenantId]);
        
        try {
            // 2. Initialize tenancy context
            tenancy()->initialize($tenant);

            // 3. Create a single company
            $company = Company::create([
                'name' => 'Test Company',
                'renspa' => '123456789012',
                'location' => 'Test Location',
                'is_active' => true,
            ]);

            // 4. Create first BatchType
            BatchType::create([
                'company_id' => $company->id,
                'name' => 'Operational 1',
                'code' => 'OPERATIONAL',
                'description' => 'First Operational type',
                'is_active' => true,
            ]);

            $this->expectException(QueryException::class);

            // 5. Create second BatchType with same code in same company (should fail due to unique constraint)
            BatchType::create([
                'company_id' => $company->id,
                'name' => 'Operational 2',
                'code' => 'OPERATIONAL',
                'description' => 'Second Operational type',
                'is_active' => true,
            ]);
        } finally {
            // Clean up database and end tenancy context
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            $tenant->delete();
        }
    }
}
