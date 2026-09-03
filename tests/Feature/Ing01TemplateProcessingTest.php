<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\DTOs\Ing01\Ing01SubmissionDTO;
use App\Application\Services\ActivityResolver;
use App\Application\Services\AnimalCategoryResolver;
use App\Application\Services\BreedAndColorResolver;
use App\Application\Services\Ing01TemplateProcessor;
use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\Breed;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\Color;
use App\Models\Company;
use App\Models\Farm;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ActivitySeeder;
use Database\Seeders\AnimalCategorySeeder;
use Database\Seeders\BreedSeeder;
use Database\Seeders\ColorSeeder;
use Database\Seeders\WorkTemplateSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Ing01TemplateProcessingTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->tenant = Tenant::create(['id' => 'test-ing01-' . uniqid()]);
        $this->tenant->domains()->create(['domain' => 'test-ing01.localhost']);
        tenancy()->initialize($this->tenant);

        $this->company = Company::first() ?? Company::create([
            'name' => 'Cabaña San Jorge',
            'renspa' => '01.234.5.67890/01',
            'location' => 'Ruta 5 Km 120',
            'is_active' => true,
        ]);

        $this->seed(ActivitySeeder::class);
        $this->seed(BreedSeeder::class);
        $this->seed(ColorSeeder::class);
        $this->seed(AnimalCategorySeeder::class);
        $this->seed(WorkTemplateSeeder::class);

        $this->user = User::first() ?? User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('secret123'),
        ]);

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

    public function test_caravans_table_has_no_breed_string_column(): void
    {
        $this->assertFalse(Schema::hasColumn('caravans', 'breed'));
        $this->assertTrue(Schema::hasColumn('caravans', 'breed_id'));
        $this->assertTrue(Schema::hasColumn('caravans', 'color_id'));
        $this->assertTrue(Schema::hasColumn('caravans', 'teeth'));
    }

    public function test_category_resolver_implements_parent_first_resolution(): void
    {
        $resolver = new AnimalCategoryResolver();

        // 1. Toro
        $toroRes = $resolver->resolve('TORO');
        $this->assertTrue($toroRes->isResolved);
        $this->assertEquals('TORO', $toroRes->categoryCode);
        $this->assertNull($toroRes->subcategoryId);
        $this->assertEquals('M', $toroRes->sex);

        // 2. Vaquillona Reposición
        $vaqRes = $resolver->resolve('VAQUILLONA REPOSICION');
        $this->assertTrue($vaqRes->isResolved);
        $this->assertEquals('VAQUILLONA', $vaqRes->categoryCode);
        $this->assertEquals('REPOSICION', $vaqRes->subcategoryCode);
        $this->assertEquals('H', $vaqRes->sex);

        // 3. Vaca CUT
        $vacaCutRes = $resolver->resolve('VACA CUT');
        $this->assertTrue($vacaCutRes->isResolved);
        $this->assertEquals('VACA', $vacaCutRes->categoryCode);
        $this->assertEquals('DESCARTE_CUT', $vacaCutRes->subcategoryCode);
        $this->assertEquals('H', $vacaCutRes->sex);

        // 4. Ternera (inferred sex = H)
        $terneraRes = $resolver->resolve('TERNERA');
        $this->assertTrue($terneraRes->isResolved);
        $this->assertEquals('TERNERO', $terneraRes->categoryCode);
        $this->assertEquals('H', $terneraRes->sex);

        // 5. Ternero (inferred sex = M)
        $terneroRes = $resolver->resolve('TERNERO');
        $this->assertTrue($terneroRes->isResolved);
        $this->assertEquals('TERNERO', $terneroRes->categoryCode);
        $this->assertEquals('M', $terneroRes->sex);

        // 6. Orphaned modifier without parent (No-Hallucination policy)
        $orphanRes = $resolver->resolve('REPOSICION');
        $this->assertFalse($orphanRes->isResolved);
        $this->assertTrue($orphanRes->requiresReview);
        $this->assertNull($orphanRes->categoryId);
        $this->assertNull($orphanRes->subcategoryId);
    }

    public function test_activity_resolver_implements_deterministic_resolution(): void
    {
        $resolver = new ActivityResolver();

        // 1. Exact Name match with accent
        $resCria = $resolver->resolve('Cría');
        $this->assertTrue($resCria->isResolved);
        $this->assertEquals('CRIA', $resCria->activityCode);
        $this->assertEquals('Cría', $resCria->activityName);

        // 2. Unaccented Name match
        $resRecria = $resolver->resolve('recria');
        $this->assertTrue($resRecria->isResolved);
        $this->assertEquals('RECRIA', $resRecria->activityCode);
        $this->assertEquals('Recría', $resRecria->activityName);

        // 3. Exact Code match
        $resInv = $resolver->resolve('INVERNADA');
        $this->assertTrue($resInv->isResolved);
        $this->assertEquals('INVERNADA', $resInv->activityCode);

        // 4. Synonym match (engorde -> INVERNADA, feedlot -> INVERNADA)
        $resEngorde = $resolver->resolve('engorde');
        $this->assertTrue($resEngorde->isResolved);
        $this->assertEquals('INVERNADA', $resEngorde->activityCode);

        // 5. Null or blank input
        $resNull = $resolver->resolve(null);
        $this->assertFalse($resNull->isResolved);
        $this->assertNull($resNull->activityId);
    }

    public function test_work_template_ing01_returns_complete_schema_definition_with_provider_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => (string)$this->company->id])
            ->getJson('http://test-ing01.localhost/api/work-templates/ING-01');

        $response->assertStatus(200);
        $response->assertJsonPath('code', 'ING-01');
        $response->assertJsonPath('category', 'ENTRY');
        $response->assertJsonStructure([
            'schema_definition' => [
                'header_fields',
                'table_columns',
            ],
        ]);

        $fields = collect($response->json('schema_definition.header_fields'))->pluck('name')->toArray();
        $this->assertContains('provider_name', $fields);
        $this->assertContains('provider_cuit', $fields);
        $this->assertContains('provider_farm_name', $fields);
        $this->assertContains('provider_renspa', $fields);
        $this->assertContains('provider_batch_name', $fields);
        $this->assertContains('lote', $fields);
        $this->assertContains('activity', $fields);
    }

    public function test_can_process_and_persist_ing01_entry_with_breed_color_and_teeth(): void
    {
        $payload = [
            'batch_name'         => 'LOTE INGRESO 2026-A',
            'activity'           => 'Recría',
            'entry_date'         => '28 / 08 / 2026',
            'provider_name'      => 'Ganadera del Sur SRL',
            'provider_farm_name' => 'Establecimiento Norte',
            'provider_cuit'      => '30-98765432-1',
            'provider_renspa'    => '02.123.4.56789/00',
            'guia_dte'           => 'DTE-99214',
            'caravans'           => [
                [
                    'caravana'     => 'TAG-101',
                    'category'     => 'VAQUILLONA REPOSICION',
                    'breed'        => 'Angus Negro',
                    'teeth'        => '2D',
                    'entry_weight' => '295.50',
                    'observations' => 'Excelente estado sanitario',
                ],
                [
                    'caravana'     => 'TAG-102',
                    'category'     => 'TORO',
                    'breed'        => 'Hereford',
                    'teeth'        => '4D',
                    'entry_weight' => 620.00,
                    'observations' => 'Reproductores Puros',
                ],
                [
                    'caravana'     => 'TAG-103',
                    'category'     => 'TERNERA',
                    'breed'        => 'Angus Colorado',
                    'teeth'        => 'DL',
                    'entry_weight' => '185.00',
                    'observations' => 'Al pie de madre',
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => (string)$this->company->id])
            ->postJson('http://test-ing01.localhost/api/work-templates/ing-01/process', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.total_processed', 3);
        $response->assertJsonPath('data.batch.activity_code', 'RECRIA');

        // 1. Verify Batch created on company's own farm with activity
        $batch = Batch::with('activity')->where('name', 'LOTE INGRESO 2026-A')->first();
        $this->assertNotNull($batch);
        $this->assertEquals($this->company->id, $batch->company_id);
        $this->assertNotNull($batch->activity_id);
        $this->assertEquals('RECRIA', $batch->activity?->code);

        $ownFarm = Farm::where('company_id', $this->company->id)->whereNull('provider_id')->first();
        $this->assertNotNull($ownFarm);
        $this->assertEquals($ownFarm->id, $batch->farm_id);

        // 2. Verify Caravan 1 (TAG-101) -> Angus Negro, 2D, Entry Date normalized to 2026-08-28
        $c1 = Caravan::with(['breedRelation', 'colorRelation'])->where('identification', 'TAG-101')->first();
        $this->assertNotNull($c1);
        $this->assertEquals($batch->id, $c1->batch_id);
        $this->assertEquals('2026-08-28', $c1->entry_date->format('Y-m-d'));
        $this->assertEquals(\App\Core\Enums\AnimalSex::FEMALE, $c1->sex);
        $this->assertEquals(295.50, (float)$c1->entry_weight);
        $this->assertEquals(2, $c1->teeth);
        $this->assertEquals('Angus', $c1->breedRelation?->name);
        $this->assertEquals('Negro', $c1->colorRelation?->name);

        // 3. Verify Caravan 3 (TAG-103) -> Angus Colorado, DL (0)
        $c3 = Caravan::with(['breedRelation', 'colorRelation'])->where('identification', 'TAG-103')->first();
        $this->assertNotNull($c3);
        $this->assertEquals(0, $c3->teeth);
        $this->assertEquals('Angus', $c3->breedRelation?->name);
        $this->assertEquals('Colorado', $c3->colorRelation?->name);
    }

    public function test_can_process_external_provider_batch_and_create_named_provider_farm(): void
    {
        $payload = [
            'batch_name'          => null, // Own batch is empty
            'provider_batch_name' => 'TROPA-EXT-500',
            'provider_name'       => 'Ganadera del Sur SRL',
            'provider_farm_name'  => 'Establecimiento Norte',
            'provider_cuit'       => '30-11223344-5',
            'provider_renspa'     => '03.999.8.77777/01',
            'caravans'            => [
                [
                    'caravana'     => 'EXT-001',
                    'category'     => 'NOVILLITO',
                    'breed'        => 'Brangus Colorado',
                    'teeth'        => '2D',
                    'entry_weight' => 280.00,
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => (string)$this->company->id])
            ->postJson('http://test-ing01.localhost/api/work-templates/ing-01/process', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.batch.is_external', true);
        $response->assertJsonPath('data.batch.name', 'TROPA-EXT-500');

        // Verify Provider Farm created with explicit name
        $provFarm = Farm::where('company_id', $this->company->id)
            ->where('name', 'Establecimiento Norte')
            ->first();
        $this->assertNotNull($provFarm);
        $this->assertEquals('03.999.8.77777/01', $provFarm->renspa);
        $this->assertNotNull($provFarm->provider_id);

        $extCaravan = Caravan::with(['breedRelation', 'colorRelation'])->where('identification', 'EXT-001')->first();
        $this->assertNotNull($extCaravan);
        $this->assertEquals('Brangus', $extCaravan->breedRelation?->name);
        $this->assertEquals('Colorado', $extCaravan->colorRelation?->name);
        $this->assertEquals(2, $extCaravan->teeth);
    }
}
