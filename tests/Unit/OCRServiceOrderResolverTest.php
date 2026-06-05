<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Services\OCRServiceOrderResolver;
use App\Models\ServiceOrder;
use App\Models\Provider;
use App\Models\Farm;
use App\Models\Batch;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use App\Models\Tenant;
use App\Models\Company;

final class OCRServiceOrderResolverTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $company;
    private int $batchId;
    private OCRServiceOrderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->tenant = Tenant::create(['id' => 'test-tenant-' . uniqid()]);
        $this->tenant->domains()->create(['domain' => 'test.localhost']);
        tenancy()->initialize($this->tenant);

        $this->company = Company::create([
            'name'     => 'Test Company',
            'renspa'   => '111111111111',
            'location' => 'Location',
            'is_active'=> true,
        ]);

        $provider = Provider::create(['name' => 'Provider', 'cuit' => '30-11111111-9', 'is_active' => true]);
        $farm = Farm::create(['name' => 'Farm', 'renspa' => '11.11.1.11111/11', 'provider_id' => $provider->id, 'is_active' => true]);
        $batch = Batch::create(['name' => 'Batch', 'farm_id' => $farm->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->batchId = $batch->id;

        $this->resolver = new OCRServiceOrderResolver();
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

    public function test_can_resolve_direct_service_order_from_first_row(): void
    {
        $so = ServiceOrder::create([
            'company_id'         => $this->company->id,
            'batch_id'           => $this->batchId,
            'code'               => 'SO-12345',
            'status'             => 'APPROVED',
            'service_type'       => 'IATF',
            'planned_start_date' => date('Y-m-d'),
        ]);

        $analysis = [
            'tables' => [
                [
                    'headers' => ['establecimiento', 'service_order'],
                    'rows'    => [
                        [
                            'establecimiento' => ['value' => 'Ranch'],
                            'service_order'   => ['value' => 'SO-12345'],
                        ]
                    ]
                ]
            ]
        ];

        $resolved = $this->resolver->resolve($analysis, null, $this->company->id);

        $this->assertNotNull($resolved);
        $this->assertSame($so->id, $resolved->id);
    }

    public function test_can_resolve_split_service_order_concatenated(): void
    {
        $so = ServiceOrder::create([
            'company_id'         => $this->company->id,
            'batch_id'           => $this->batchId,
            'code'               => 'SO-20260605-092008-5727',
            'status'             => 'APPROVED',
            'service_type'       => 'IATF',
            'planned_start_date' => date('Y-m-d'),
        ]);

        $analysis = [
            'tables' => [
                [
                    'headers' => ['establecimiento', 'service_order'],
                    'rows'    => [
                        [
                            'establecimiento' => ['value' => 'Ranch'],
                            'service_order'   => ['value' => 'SO-20260605-'],
                        ],
                        [
                            'establecimiento' => ['value' => ''],
                            'service_order'   => ['value' => '092008-5727'],
                        ]
                    ]
                ]
            ]
        ];

        $resolved = $this->resolver->resolve($analysis, null, $this->company->id);

        $this->assertNotNull($resolved);
        $this->assertSame($so->id, $resolved->id);
    }

    public function test_fallback_to_filename(): void
    {
        $so = ServiceOrder::create([
            'company_id'         => $this->company->id,
            'batch_id'           => $this->batchId,
            'code'               => 'SO-20260605-085740-4334',
            'status'             => 'APPROVED',
            'service_type'       => 'IATF',
            'planned_start_date' => date('Y-m-d'),
        ]);

        $file = UploadedFile::fake()->create('Planilla_De_REP-01_SO-20260605-085740-4334_filled.pdf');

        $resolved = $this->resolver->resolve([], $file, $this->company->id);

        $this->assertNotNull($resolved);
        $this->assertSame($so->id, $resolved->id);
    }

    public function test_can_resolve_service_order_with_spaces_in_table(): void
    {
        $so = ServiceOrder::create([
            'company_id'         => $this->company->id,
            'batch_id'           => $this->batchId,
            'code'               => 'SO-20260605-085740-4334',
            'status'             => 'APPROVED',
            'service_type'       => 'IATF',
            'planned_start_date' => date('Y-m-d'),
        ]);

        $analysis = [
            'tables' => [
                [
                    'headers' => ['establecimiento', 'service_order'],
                    'rows'    => [
                        [
                            'establecimiento' => ['value' => 'Ranch B'],
                            'service_order'   => ['value' => 'SO-20260605- 085740-4334'],
                        ]
                    ]
                ]
            ]
        ];

        $resolved = $this->resolver->resolve($analysis, null, $this->company->id);

        $this->assertNotNull($resolved);
        $this->assertSame($so->id, $resolved->id);
    }

    public function test_can_resolve_service_order_with_spaces_in_metadata(): void
    {
        $so = ServiceOrder::create([
            'company_id'         => $this->company->id,
            'batch_id'           => $this->batchId,
            'code'               => 'SO-20260605-085740-4334',
            'status'             => 'APPROVED',
            'service_type'       => 'IATF',
            'planned_start_date' => date('Y-m-d'),
        ]);

        $analysis = [
            'metadata' => [
                'service_order' => 'SO-20260605- 085740-4334'
            ]
        ];

        $resolved = $this->resolver->resolve($analysis, null, $this->company->id);

        $this->assertNotNull($resolved);
        $this->assertSame($so->id, $resolved->id);
    }

    public function test_can_resolve_candidate_code_without_database_record(): void
    {
        // No ServiceOrder created in DB
        $analysis = [
            'tables' => [
                [
                    'headers' => ['establecimiento', 'service_order'],
                    'rows'    => [
                        [
                            'establecimiento' => ['value' => 'Ranch B'],
                            'service_order'   => ['value' => 'SO-20260605- 085740-4334'],
                        ]
                    ]
                ]
            ]
        ];

        $candidateCode = $this->resolver->resolveCandidateCode($analysis, null);

        $this->assertSame('SO-20260605-085740-4334', $candidateCode);
    }
}

