<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Infrastructure\OCR\AzureOCRProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class WorkTemplateIdentifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_identify_work_template_from_document(): void
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

            // Mock AzureOCRProvider
            $mockOcr = Mockery::mock(AzureOCRProvider::class);
            $mockOcr->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'tables' => [
                        [
                            'table_id' => 0,
                            'row_count' => 2,
                            'column_count' => 2,
                            'headers' => ['establecimiento', 'template_code'],
                            'rows' => [
                                [
                                    'establecimiento' => ['value' => 'La Julia', 'confidence' => 0.99],
                                    'template_code' => ['value' => 'REP-01', 'confidence' => 0.99]
                                ]
                            ]
                        ]
                    ],
                    'metadata' => [
                        'template_code' => 'REP-01',
                        'cuit' => '30-12345678-9',
                        'renspa' => '12.345.6.78910/11',
                        'lote' => 'Lote 5'
                    ]
                ]);

            $this->app->instance(AzureOCRProvider::class, $mockOcr);

            // Mock CompanyContext
            $companyContext = new \App\Core\Contexts\CompanyContext();
            $companyContext->setCompanyId($company->id);
            $this->app->instance(\App\Core\Interfaces\ICompanyContext::class, $companyContext);

            // Create a dummy file
            $file = UploadedFile::fake()->create('planilla.pdf', 100);

            $response = $this->actingAs($user)
                ->withHeaders(['X-Company-Id' => (string) $company->id])
                ->postJson('/api/work-templates/identify', [
                    'document' => $file,
                    'provider' => 'azure',
                ]);

            $response->assertStatus(200);
            $response->assertJsonPath('status', 'success');
            $response->assertJsonPath('identified_template.code', 'REP-01');
            $response->assertJsonPath('identified_template.category', 'REPRODUCTIVE');
            $response->assertJsonPath('context.cuit', '30-12345678-9');

        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            $tenant->delete();
        }
    }
}
