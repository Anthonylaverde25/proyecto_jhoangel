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
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegisterNewCaravansTest extends TestCase
{
    private const URL_LOOKUP = 'http://test.localhost/api/caravans/lookup';
    private const URL_REGISTER = 'http://test.localhost/api/caravans/register-new';

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
            'name' => 'Lote Manga BLE',
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

    public function test_registers_all_new_caravans(): void
    {
        $response = $this->postJson(self::URL_REGISTER, $this->payload([
            $this->row('032000000900001', 'H'),
            $this->row('032000000900002', 'M'),
        ]));

        $response->assertStatus(201)->assertJsonPath('data.registered', 2);

        $female = Caravan::where('identification', '032000000900001')->first();
        $this->assertNotNull($female);
        $this->assertSame($this->company->id, (int) $female->company_id);
        $this->assertSame($this->batch->id, (int) $female->batch_id);
        $this->assertNotNull($female->femaleDetail, 'A new female keeps getting her reproductive detail.');
    }

    public function test_one_existing_caravan_rejects_the_whole_submission(): void
    {
        $this->makeCaravan('032000000900001', $this->company->id, teeth: 4);

        $response = $this->postJson(self::URL_REGISTER, $this->payload([
            $this->row('032000000900001', 'H'),
            $this->row('032000000900002', 'M'),
        ]));

        $response->assertStatus(409)
            ->assertJsonPath('conflicts.0.identification', '032000000900001')
            ->assertJsonPath('conflicts.0.status', 'own_company');

        $this->assertNull(Caravan::where('identification', '032000000900002')->first());
        $this->assertSame(4, (int) Caravan::where('identification', '032000000900001')->value('teeth'),
            'The existing animal must not be overwritten.');
    }

    public function test_caravan_of_another_company_is_rejected_not_transferred(): void
    {
        $this->makeCaravan('032000000900003', $this->otherCompany->id);

        $response = $this->postJson(self::URL_REGISTER, $this->payload([
            $this->row('032000000900003', 'M'),
        ]));

        $response->assertStatus(409)->assertJsonPath('conflicts.0.status', 'other_company');

        $holder = Caravan::withoutGlobalScopes()->where('identification', '032000000900003')->value('company_id');
        $this->assertSame($this->otherCompany->id, (int) $holder);
    }

    public function test_retried_submission_returns_the_same_response_without_duplicating(): void
    {
        $payload = $this->payload([$this->row('032000000900004', 'M')]);

        $first = $this->postJson(self::URL_REGISTER, $payload);
        $second = $this->postJson(self::URL_REGISTER, $payload);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(1, Caravan::where('identification', '032000000900004')->count());
    }

    public function test_rejects_identifications_that_are_not_15_digits(): void
    {
        $this->postJson(self::URL_REGISTER, $this->payload([
            $this->row('03200000090000', 'M'),
            $this->row('0320000009000055', 'M'),
            $this->row('032 00000090000', 'M'),
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'caravans.0.identification',
            'caravans.1.identification',
            'caravans.2.identification',
        ]);
    }

    public function test_rejects_the_same_caravan_twice_in_one_submission(): void
    {
        $this->postJson(self::URL_REGISTER, $this->payload([
            $this->row('032000000900005', 'M'),
            $this->row('032000000900005', 'M'),
        ]))->assertStatus(422)->assertJsonValidationErrors(['caravans.0.identification']);
    }

    public function test_rejects_a_batch_of_another_company(): void
    {
        $foreignBatch = Batch::create([
            'company_id' => $this->otherCompany->id,
            'name' => 'Lote Ajeno',
            'is_active' => true,
        ]);

        $row = $this->row('032000000900006', 'M');
        $row['batch_id'] = $foreignBatch->id;

        $this->postJson(self::URL_REGISTER, $this->payload([$row]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['caravans.0.batch_id']);
    }

    public function test_lookup_classifies_each_caravan_and_hides_other_company_details(): void
    {
        $this->makeCaravan('032000000900007', $this->company->id);
        $this->makeCaravan('032000000900008', $this->otherCompany->id);

        $response = $this->postJson(self::URL_LOOKUP, [
            'identifications' => ['032000000900007', '032000000900008', '032000000900009'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'own_company')
            ->assertJsonPath('data.0.batch_name', 'Lote Manga BLE')
            ->assertJsonPath('data.1.status', 'other_company')
            ->assertJsonMissingPath('data.1.caravan_id')
            ->assertJsonMissingPath('data.1.batch_name')
            ->assertJsonPath('data.2.status', 'not_found');
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson(self::URL_LOOKUP, ['identifications' => ['032000000900010']])->assertStatus(401);
        $this->postJson(self::URL_REGISTER, $this->payload([$this->row('032000000900010', 'M')]))->assertStatus(401);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function payload(array $rows): array
    {
        return ['submission_id' => (string) Str::uuid(), 'caravans' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $identification, string $sex): array
    {
        return [
            'identification' => $identification,
            'sex' => $sex,
            'teeth' => 0,
            'batch_id' => $this->batch->id,
            'entry_date' => '2026-09-24',
        ];
    }

    private function makeCaravan(string $identification, int $companyId, int $teeth = 0): void
    {
        Caravan::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'batch_id' => $companyId === $this->company->id ? $this->batch->id : null,
            'renspa' => 'NO_DEFINIDO',
            'identification' => $identification,
            'teeth' => $teeth,
            'sex' => AnimalSex::MALE,
        ]);
    }
}
