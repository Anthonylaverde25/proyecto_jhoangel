<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\WorkTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_work_templates_for_company(): void
    {
        $tenant = Tenant::create(['id' => 'tenant-' . uniqid()]);
        $tenant->domains()->create(['domain' => 'localhost']);
        
        try {
            tenancy()->initialize($tenant);

            $company = Company::first();
            $this->assertNotNull($company);

            $user = User::first() ?? User::create([
                'name'       => 'Test User',
                'email'      => 'test@example.com',
                'password'   => bcrypt('password'),
            ]);

            // Simulating company context header
            $companyContext = new \App\Core\Contexts\CompanyContext();
            $companyContext->setCompanyId($company->id);
            $this->app->instance(\App\Core\Interfaces\ICompanyContext::class, $companyContext);

            $response = $this->actingAs($user)
                ->withHeaders(['X-Company-Id' => (string) $company->id])
                ->getJson('/api/work-templates');

            $response->assertStatus(200);
            $response->assertJsonPath('0.code', 'ING-01');
            $response->assertJsonPath('0.category', 'ENTRY');

        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            $tenant->delete();
        }
    }

    public function test_can_get_work_template_by_code(): void
    {
        $tenant = Tenant::create(['id' => 'tenant-' . uniqid()]);
        $tenant->domains()->create(['domain' => 'localhost']);
        
        try {
            tenancy()->initialize($tenant);

            $company = Company::first();
            $this->assertNotNull($company);

            $user = User::first() ?? User::create([
                'name'       => 'Test User',
                'email'      => 'test@example.com',
                'password'   => bcrypt('password'),
            ]);

            // Mock CompanyContext
            $companyContext = new \App\Core\Contexts\CompanyContext();
            $companyContext->setCompanyId($company->id);
            $this->app->instance(\App\Core\Interfaces\ICompanyContext::class, $companyContext);

            $response = $this->actingAs($user)
                ->withHeaders(['X-Company-Id' => (string) $company->id])
                ->getJson('/api/work-templates/REP-01');

            $response->assertStatus(200);
            $response->assertJsonPath('code', 'REP-01');
            $response->assertJsonPath('category', 'REPRODUCTIVE');

            // Test non-existing code
            $responseNotFound = $this->actingAs($user)
                ->withHeaders(['X-Company-Id' => (string) $company->id])
                ->getJson('/api/work-templates/NON-EXISTENT');

            $responseNotFound->assertStatus(404);

        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            $tenant->delete();
        }
    }
}
