<?php

declare(strict_types=1);

namespace Tests\Feature\Caravans;

use App\Core\Enums\AnimalSex;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The record the phone shows after reading a caravan: GET /caravans/{id}, with the id that
 * /caravans/lookup returns for the active company's animals.
 */
class ShowCaravanTest extends TestCase
{
    private const URL = 'http://test.localhost/api/caravans/';

    private Tenant $tenant;
    private Company $company;
    private Company $otherCompany;
    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->tenant = Tenant::create(['id' => 'test-tenant-' . uniqid()]);
        $this->tenant->domains()->create(['domain' => 'test.localhost']);
        tenancy()->initialize($this->tenant);

        $this->company = Company::first();
        $this->assertNotNull($this->company);
        $this->otherCompany = Company::create(['name' => 'Otra Empresa', 'is_active' => true]);

        $companyContext = new \App\Core\Contexts\CompanyContext();
        $companyContext->setCompanyId($this->company->id);
        $this->app->instance(\App\Core\Interfaces\ICompanyContext::class, $companyContext);

        $user = User::first() ?? User::create([
            'company_id' => $this->company->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->batch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Rodeo Cría Sur',
            'is_active' => true,
        ]);
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

    public function test_returns_the_record_of_an_own_caravan(): void
    {
        $caravan = $this->makeCaravan('032000000980001', $this->company->id, teeth: 6);

        $this->getJson(self::URL . $caravan->id)
            ->assertOk()
            ->assertJsonPath('data.id', $caravan->id)
            ->assertJsonPath('data.identification', '032000000980001')
            ->assertJsonPath('data.teeth', 6)
            ->assertJsonPath('data.sex', AnimalSex::MALE->value)
            ->assertJsonPath('data.batch.name', 'Rodeo Cría Sur');
    }

    public function test_the_id_from_lookup_opens_the_record(): void
    {
        $this->makeCaravan('032000000980002', $this->company->id);

        $id = $this->postJson(self::URL . 'lookup', ['identifications' => ['032000000980002']])
            ->assertOk()
            ->json('data.0.caravan_id');

        $this->getJson(self::URL . $id)->assertOk()->assertJsonPath('data.identification', '032000000980002');
    }

    public function test_another_company_animal_is_not_found(): void
    {
        $foreign = $this->makeCaravan('032000000980003', $this->otherCompany->id);

        $this->getJson(self::URL . $foreign->id)->assertNotFound();
    }

    public function test_unknown_id_is_not_found(): void
    {
        $this->getJson(self::URL . '999999')->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        $caravan = $this->makeCaravan('032000000980004', $this->company->id);
        $this->app['auth']->forgetGuards();

        $this->getJson(self::URL . $caravan->id)->assertStatus(401);
    }

    private function makeCaravan(string $identification, int $companyId, int $teeth = 0): Caravan
    {
        return Caravan::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'batch_id' => $companyId === $this->company->id ? $this->batch->id : null,
            'renspa' => 'NO_DEFINIDO',
            'identification' => $identification,
            'teeth' => $teeth,
            'sex' => AnimalSex::MALE,
        ]);
    }
}
