<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkTemplateIdentifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_identify_work_template_from_document_via_ai_agent(): void
    {
        $tenant = Tenant::create(['id' => 'tenant-' . uniqid()]);
        $tenant->domains()->create(['domain' => 'localhost']);

        try {
            tenancy()->initialize($tenant);

            $company = Company::first();
            $this->assertNotNull($company);

            $user = User::first() ?? User::create([
                'name'     => 'Test User',
                'email'    => 'test@example.com',
                'password' => bcrypt('password'),
            ]);

            // Fake the AI Agent microservice response
            Http::fake([
                '*api/v1/templates/analyze*' => Http::response([
                    'status' => 'success',
                    'identified_template' => [
                        'id'       => 1,
                        'code'     => 'ING-01',
                        'title'    => 'Ingreso de Compra Directa',
                        'category' => 'INGRESS',
                    ],
                    'detection_confidence' => 0.98,
                    'context' => [
                        'lote'               => 'LOTE 104',
                        'fecha'              => '2026-08-27',
                        'cuit'               => '30-12345678-9',
                        'renspa'             => '12.345.6.78910/11',
                        'service_order_code' => 'DTE-2026-94821',
                    ],
                    'data' => [
                        [
                            'mapped_rows' => [
                                [
                                    'identification' => ['value' => 'AR-401', 'confidence' => 0.95],
                                    'category'       => ['value' => 'Vaquillona Reposicion', 'confidence' => 0.95],
                                    'sex'            => ['value' => 'H', 'confidence' => 0.95],
                                    'breed'          => ['value' => 'Angus Negro', 'confidence' => 0.95],
                                    'teeth'          => ['value' => 2, 'confidence' => 0.95],
                                    'entry_weight'   => ['value' => 315.0, 'confidence' => 0.95],
                                    'observations'   => ['value' => 'Buen estado', 'confidence' => 0.95],
                                ],
                            ],
                            'total_detected' => 1,
                        ],
                    ],
                ], 200),
            ]);

            // Mock CompanyContext
            $companyContext = new \App\Core\Contexts\CompanyContext();
            $companyContext->setCompanyId($company->id);
            $this->app->instance(\App\Core\Interfaces\ICompanyContext::class, $companyContext);

            // Create a dummy file
            $file = UploadedFile::fake()->create('planilla.png', 100, 'image/png');

            $response = $this->actingAs($user)
                ->withHeaders(['X-Company-Id' => (string) $company->id])
                ->post('/api/work-templates/identify', [
                    'document' => $file,
                ]);

            $response->assertStatus(200);
            $response->assertJsonPath('status', 'success');
            $response->assertJsonPath('identified_template.code', 'ING-01');
            $response->assertJsonPath('identified_template.category', 'INGRESS');
            $response->assertJsonPath('context.cuit', '30-12345678-9');
            $response->assertJsonPath('context.lote', 'LOTE 104');
            $response->assertJsonPath('data.0.total_detected', 1);

        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            $tenant->delete();
        }
    }
}
