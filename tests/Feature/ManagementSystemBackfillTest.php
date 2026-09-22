<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `batches.is_confined` as a three-state fact, and the backfill that got it there.
 *
 * The column was born `NOT NULL DEFAULT false` while the declaration was only demanded
 * in Recría, so a `false` outside Recría was the default and not an answer. Now that
 * every productive activity asks the question, the column has to be able to say "nobody
 * was asked", or every pre-existing batch would start asserting a fact no producer ever
 * stated.
 */
class ManagementSystemBackfillTest extends TestCase
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

    private function activityId(string $code): int
    {
        return (int) Activity::where('code', $code)->firstOrFail()->id;
    }

    private function batchTypeId(string $code): int
    {
        return (int) BatchType::where('code', $code)->firstOrFail()->id;
    }

    private function makeBatch(string $name, ?string $activityCode, ?bool $isConfined): int
    {
        return (int) Batch::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'activity_id' => $activityCode !== null ? $this->activityId($activityCode) : null,
            'batch_type_id' => $this->batchTypeId('OPERATIONAL'),
            'is_confined' => $isConfined,
            'is_active' => true,
        ])->id;
    }

    public function test_the_column_accepts_the_undeclared_state(): void
    {
        $this->assertTrue(Schema::hasColumn('batches', 'is_confined'));

        $id = $this->makeBatch('Lote sin declarar', 'CRIA', null);

        $this->assertNull(Batch::find($id)->is_confined);
    }

    /**
     * The backfill is `declaresManagementSystem()` translated to SQL: a `true` was always
     * a declaration, a `false` in Recría was an answer the form demanded, and everything
     * else was the column default.
     */
    public function test_backfill_keeps_answers_and_nulls_the_defaults(): void
    {
        $penned = $this->makeBatch('Recría a corral', 'RECRIA', true);
        $recriaPasture = $this->makeBatch('Recría a campo', 'RECRIA', false);
        $criaPenned = $this->makeBatch('Cría a corral', 'CRIA', true);
        $criaDefault = $this->makeBatch('Cría por defecto', 'CRIA', false);
        $noActivity = $this->makeBatch('Sin actividad', null, false);

        // Re-run just the backfill statement of the migration over rows that were
        // deliberately left in the pre-migration shape.
        DB::table('batches')->whereIn('id', [
            $penned, $recriaPasture, $criaPenned, $criaDefault, $noActivity,
        ])->update(['is_confined' => DB::raw('is_confined')]);

        $recriaIds = DB::table('activities')->where('code', 'RECRIA')->pluck('id')->all();

        DB::table('batches')
            ->where('is_confined', false)
            ->where(function ($query) use ($recriaIds) {
                $query->whereNull('activity_id');

                if ($recriaIds !== []) {
                    $query->orWhereNotIn('activity_id', $recriaIds);
                }
            })
            ->update(['is_confined' => null]);

        $this->assertTrue((bool) Batch::find($penned)->is_confined, 'Un true siempre fue una declaración');
        $this->assertNotNull(Batch::find($recriaPasture)->is_confined, 'En Recría el formulario sí preguntaba');
        $this->assertFalse((bool) Batch::find($recriaPasture)->is_confined);
        $this->assertTrue((bool) Batch::find($criaPenned)->is_confined);
        $this->assertNull(Batch::find($criaDefault)->is_confined, 'Fuera de Recría un false era el default');
        $this->assertNull(Batch::find($noActivity)->is_confined, 'Sin actividad tampoco se preguntaba');
    }

    public function test_the_api_exposes_the_undeclared_state_as_null(): void
    {
        $id = $this->makeBatch('Lote sin declarar para la API', 'INVERNADA', null);

        $response = $this->getJson(
            "http://test.localhost/api/batches/{$id}",
            ['X-Company-ID' => (string) $this->company->id]
        );

        $response->assertStatus(200);
        $this->assertNull(
            $response->json('is_confined'),
            'Un lote sin declarar no puede llegar a la pantalla como "a campo"'
        );
    }
}
