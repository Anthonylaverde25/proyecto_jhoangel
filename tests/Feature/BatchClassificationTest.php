<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Batch type catalogue constrained by activity, and the management system of the
 * batch instance (confined pen vs. extensive pasture) as an orthogonal axis.
 */
class BatchClassificationTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;

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

    private function headers(): array
    {
        return ['X-Company-ID' => (string) $this->company->id];
    }

    private function activityId(string $code): int
    {
        return (int) Activity::where('code', $code)->firstOrFail()->id;
    }

    private function batchTypeId(string $code): int
    {
        return (int) BatchType::where('code', $code)->firstOrFail()->id;
    }

    public function test_catalogue_has_twelve_types_with_their_activities(): void
    {
        $this->assertSame(12, BatchType::count());
        $this->assertNull(BatchType::where('code', 'GROWING_CONFINED')->first());

        $expected = [
            'OPERATIONAL' => null,
            'RESERVE' => null,
            'SERVICE' => 'CRIA',
            'WEANING' => 'CRIA',
            'GROWING_HEIFERS' => 'RECRIA',
            'GROWING_STEERS' => 'RECRIA',
            'GROWING_REPLACEMENT_FEMALES' => 'RECRIA',
            'GROWING_REPLACEMENT_BULLS' => 'RECRIA',
            'GROWING_MIXED' => 'RECRIA',
            'QUARANTINE' => 'INTERNAL',
            'INTERNAL_CONSUMPTION' => 'INTERNAL',
            'INTERNAL_DEATH' => 'INTERNAL',
        ];

        foreach ($expected as $code => $activityCode) {
            $type = BatchType::with('activity')->where('code', $code)->first();
            $this->assertNotNull($type, "Falta el tipo de lote {$code}");
            $this->assertSame(
                $activityCode,
                $type->activity?->code,
                "El tipo {$code} no quedó asignado a la actividad esperada"
            );
        }
    }

    public function test_only_the_reserve_type_is_hidden_from_the_manual_selectors(): void
    {
        // The reserve batch is created by GetOrCreateReserveBatchUseCase, never by hand.
        $this->assertFalse((bool) BatchType::where('code', 'RESERVE')->firstOrFail()->is_selectable);

        $this->assertSame(
            0,
            BatchType::where('code', '!=', 'RESERVE')->where('is_selectable', false)->count(),
            'Ningún otro tipo debería quedar fuera de los selectores'
        );
    }

    public function test_batch_type_selectability_is_exposed_by_the_api(): void
    {
        $response = $this->getJson('http://test.localhost/api/batch-types', $this->headers());

        $response->assertStatus(200);

        $types = collect($response->json('data') ?? $response->json());

        $this->assertFalse($types->firstWhere('code', 'RESERVE')['is_selectable']);
        $this->assertTrue($types->firstWhere('code', 'OPERATIONAL')['is_selectable']);
    }

    public function test_batch_type_activity_is_exposed_by_the_api(): void
    {
        $response = $this->getJson('http://test.localhost/api/batch-types', $this->headers());

        $response->assertStatus(200);

        $heifers = collect($response->json('data') ?? $response->json())
            ->firstWhere('code', 'GROWING_HEIFERS');

        $this->assertNotNull($heifers);
        $this->assertSame($this->activityId('RECRIA'), $heifers['activity_id']);
    }

    public function test_confined_flag_is_persisted_and_exposed(): void
    {
        $response = $this->postJson('http://test.localhost/api/batches', [
            'name' => 'Vaquillonas a corral',
            'activity_id' => $this->activityId('RECRIA'),
            'batch_type_id' => $this->batchTypeId('GROWING_HEIFERS'),
            'is_confined' => true,
        ], $this->headers());

        $response->assertStatus(201);
        $response->assertJsonPath('is_confined', true);

        $this->assertTrue((bool) Batch::find($response->json('id'))->is_confined);
    }

    public function test_rejects_a_type_that_does_not_belong_to_the_activity(): void
    {
        $response = $this->postJson('http://test.localhost/api/batches', [
            'name' => 'Vaquillonas en Cría',
            'activity_id' => $this->activityId('CRIA'),
            'batch_type_id' => $this->batchTypeId('GROWING_HEIFERS'),
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('batch_type_id');
    }

    public function test_accepts_a_cross_cutting_type_in_any_activity(): void
    {
        foreach (['CRIA', 'RECRIA', 'INVERNADA'] as $activityCode) {
            $payload = [
                'name' => 'Lote operacional ' . $activityCode,
                'activity_id' => $this->activityId($activityCode),
                'batch_type_id' => $this->batchTypeId('OPERATIONAL'),
            ];

            // Every productive activity demands a declared management system.
            $payload['is_confined'] = false;

            $this->postJson('http://test.localhost/api/batches', $payload, $this->headers())
                ->assertStatus(201);
        }
    }

    public function test_accepts_any_type_when_no_activity_is_given(): void
    {
        $this->postJson('http://test.localhost/api/batches', [
            'name' => 'Lote sin actividad',
            'batch_type_id' => $this->batchTypeId('GROWING_STEERS'),
        ], $this->headers())->assertStatus(201);
    }

    public function test_every_productive_activity_requires_the_management_system(): void
    {
        $cases = [
            'RECRIA' => 'GROWING_STEERS',
            'CRIA' => 'WEANING',
            'INVERNADA' => 'OPERATIONAL',
        ];

        foreach ($cases as $activityCode => $batchTypeCode) {
            $response = $this->postJson('http://test.localhost/api/batches', [
                'name' => "Lote sin declarar manejo {$activityCode}",
                'activity_id' => $this->activityId($activityCode),
                'batch_type_id' => $this->batchTypeId($batchTypeCode),
            ], $this->headers());

            $response->assertStatus(422, "En {$activityCode} debería exigirse el sistema de manejo");
            $response->assertJsonValidationErrors('is_confined');
        }
    }

    /**
     * The case the restriction used to make inexpressible. A Cría batch is penned just
     * as often as a Recría one; the stage was never what decided it.
     */
    public function test_a_cria_batch_can_be_declared_penned(): void
    {
        $response = $this->postJson('http://test.localhost/api/batches', [
            'name' => 'Vacas de cría a corral',
            'activity_id' => $this->activityId('CRIA'),
            'batch_type_id' => $this->batchTypeId('WEANING'),
            'is_confined' => true,
        ], $this->headers());

        $response->assertStatus(201);
        $response->assertJsonPath('is_confined', true);

        $this->assertTrue((bool) Batch::find($response->json('id'))->is_confined);
    }

    /**
     * A declared `false` is an answer and has to survive as one: it must not be stored
     * as null, which is the value reserved for a question nobody was asked.
     */
    public function test_a_declared_pasture_is_stored_as_false_not_null(): void
    {
        $response = $this->postJson('http://test.localhost/api/batches', [
            'name' => 'Novillos de invernada a campo',
            'activity_id' => $this->activityId('INVERNADA'),
            'batch_type_id' => $this->batchTypeId('OPERATIONAL'),
            'is_confined' => false,
        ], $this->headers());

        $response->assertStatus(201);
        $response->assertJsonPath('is_confined', false);

        $stored = Batch::find($response->json('id'));
        $this->assertNotNull($stored->is_confined, 'Una respuesta declarada no puede guardarse como null');
        $this->assertFalse((bool) $stored->is_confined);
    }

    /**
     * INTERNAL is not a productive stage: it holds the system's own batches, such as the
     * reserve batch. Asking how the reserve batch is fed makes no sense.
     */
    public function test_the_internal_activity_is_not_asked_for_a_management_system(): void
    {
        $response = $this->postJson('http://test.localhost/api/batches', [
            'name' => 'Cuarentena sin manejo declarado',
            'activity_id' => $this->activityId('INTERNAL'),
            'batch_type_id' => $this->batchTypeId('QUARANTINE'),
        ], $this->headers());

        $response->assertStatus(201);
        $this->assertNull(Batch::find($response->json('id'))->is_confined);
    }

    /**
     * Changing the management system never depended on the activity, and must not start
     * depending on it now that the declaration is demanded everywhere.
     */
    public function test_management_can_be_changed_on_a_cria_batch(): void
    {
        $created = $this->postJson('http://test.localhost/api/batches', [
            'name' => 'Vientres de cría a campo',
            'activity_id' => $this->activityId('CRIA'),
            'batch_type_id' => $this->batchTypeId('WEANING'),
            'is_confined' => false,
        ], $this->headers())->assertStatus(201);

        $this->patchJson(
            "http://test.localhost/api/batches/{$created->json('id')}/management",
            ['is_confined' => true],
            $this->headers()
        )->assertStatus(200)->assertJsonPath('is_confined', true);
    }

    public function test_change_management_flips_the_flag_without_touching_anything_else(): void
    {
        $created = $this->postJson('http://test.localhost/api/batches', [
            'name' => 'Novillitos invernada a corral',
            'activity_id' => $this->activityId('RECRIA'),
            'batch_type_id' => $this->batchTypeId('GROWING_STEERS'),
            'is_confined' => true,
            'weight' => 220.0,
        ], $this->headers())->assertStatus(201);

        $batchId = (int) $created->json('id');
        $before = Batch::find($batchId);
        $weightsBefore = \App\Models\BatchWeight::where('batch_id', $batchId)->count();

        $response = $this->patchJson(
            "http://test.localhost/api/batches/{$batchId}/management",
            ['is_confined' => false],
            $this->headers()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('is_confined', false);

        $after = Batch::find($batchId);
        $this->assertFalse((bool) $after->is_confined);
        $this->assertSame($before->batch_type_id, $after->batch_type_id);
        $this->assertSame($before->activity_id, $after->activity_id);
        $this->assertSame($before->current_weight, $after->current_weight);
        $this->assertSame($weightsBefore, \App\Models\BatchWeight::where('batch_id', $batchId)->count());
        $this->assertSame(0, \App\Models\CaravanMovement::where('from_batch_id', $batchId)->count());
    }
}
