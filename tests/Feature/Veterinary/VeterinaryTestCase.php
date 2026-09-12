<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\Batch;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shared fixture for the sanitary diagnostics module.
 *
 * Creating the tenant runs stancl/tenancy's CreateDatabase -> MigrateDatabase -> SeedDatabase
 * pipeline, so TenantDatabaseSeeder has already loaded the catalogues, the evidentiary
 * protocols and the demo portal links by the time a test body runs. Seeding again here would
 * duplicate diagnoses and skew every count.
 */
abstract class VeterinaryTestCase extends TestCase
{
    protected Tenant $tenant;
    protected Company $company;
    protected User $user;
    protected string $host;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');

        $suffix = uniqid();
        $this->host = 'test-vet-' . $suffix . '.localhost';

        $this->tenant = Tenant::create(['id' => 'test-vet-' . $suffix]);
        $this->tenant->domains()->create(['domain' => $this->host]);
        tenancy()->initialize($this->tenant);

        Artisan::call('tenants:migrate');

        $this->company = Company::first() ?? Company::create([
            'name' => 'Cabaña de Prueba Sanitaria',
            'cuit' => '30-71999999-9',
        ]);

        $companyContext = new \App\Core\Contexts\CompanyContext();
        $companyContext->setCompanyId($this->company->id);
        $this->app->instance(\App\Core\Interfaces\ICompanyContext::class, $companyContext);

        $this->user = User::first() ?? User::create([
            'name' => 'Administrador de Prueba',
            'email' => 'admin@prueba.com',
            'password' => bcrypt('secret123'),
        ]);

        $this->linkUserToCompany($this->user, $this->company->id);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    protected function linkUserToCompany(User $user, int $companyId, string $role = 'operator'): void
    {
        $exists = DB::table('company_user')
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->exists();

        if ($exists) {
            DB::table('company_user')
                ->where('user_id', $user->id)
                ->where('company_id', $companyId)
                ->update(['role' => $role, 'updated_at' => now()]);

            return;
        }

        DB::table('company_user')->insert([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function url(string $path): string
    {
        return 'http://' . $this->host . '/api' . $path;
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    protected function apiAs(string $method, string $path, array $payload = [])
    {
        return $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->json($method, $this->url($path), $payload);
    }

    protected function veterinarian(string $licenseNumber): Veterinarian
    {
        return Veterinarian::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('license_number', $licenseNumber)
            ->firstOrFail();
    }

    /**
     * @return list<Caravan>
     */
    protected function bulls(int $count): array
    {
        return Caravan::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereIn('sex', ['M', 'MACHO', 'MALE'])
            ->orderBy('identification')
            ->limit($count)
            ->get()
            ->all();
    }

    /**
     * Gives a catalogue professional a login with the portal role, so a test can act as them.
     * ADR-7: the role lives on `company_user` and the catalogue row points at the account.
     */
    protected function portalUserFor(Veterinarian $veterinarian, string $email): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $veterinarian->name, 'password' => bcrypt('secret123')]
        );

        $this->linkUserToCompany($user, (int) $this->company->id, 'veterinarian');

        Veterinarian::withoutGlobalScopes()
            ->where('id', $veterinarian->id)
            ->update(['user_id' => $user->id]);

        return $user;
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    protected function apiAsUser(User $user, string $method, string $path, array $payload = [])
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->json($method, $this->url($path), $payload);
    }

    protected function pathogenId(string $code): int
    {
        return (int) \App\Models\Pathogen::where('code', $code)->firstOrFail()->id;
    }

    protected function anyBatch(): Batch
    {
        return Batch::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->orderBy('id')
            ->firstOrFail();
    }
}
