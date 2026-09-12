<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Company;
use App\Models\User;
use App\Models\Veterinarian;
use App\Models\VeterinarianBatchAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the sanitary catalogues the diagnostic module depends on: laboratories of the
 * SENASA / RENALAB network, the licensed professionals who sign the reports, and the
 * batch assignments that decide what each professional sees in the portal.
 */
class VeterinaryCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();

        if (!$company) {
            $this->command?->warn('VeterinaryCatalogSeeder: no hay compañía cargada. Se omite.');

            return;
        }

        $companyId = (int) $company->id;

        $veterinarians = $this->seedVeterinarians($companyId);

        $this->seedPortalUser(
            $companyId,
            $veterinarians['ARANGUREN'],
            'faranguren@ganadero.com',
            'Dr. Fernando Aranguren (M.V.)'
        );

        // A second professional with their own login, invoicing under the same entity as
        // Aranguren (ADR-38). Signing is always personal: sharing a letterhead never transfers
        // the right to attest somebody else's work.
        $this->seedPortalUser(
            $companyId,
            $veterinarians['ROLDAN'],
            'croldan@ganadero.com',
            'Dra. Cecilia Roldán (M.V.)'
        );

        // A virgin account for testing: registered in the catalogue, with portal login, but
        // unlinked from historical protocols and with zero pre-assigned batches.
        $this->seedPortalUser(
            $companyId,
            $veterinarians['LAVERDE'],
            'alaverde@ganadero.com',
            'Dr. Anthony Laverde (M.V.)'
        );

        // Deliberately only the three acting professionals: Roldán does not work a chute of her
        // own, so she owns no troop.
        $this->seedBatchAssignments($companyId, [
            'ARANGUREN' => $veterinarians['ARANGUREN'],
            'SOSA' => $veterinarians['SOSA'],
            'QUIROGA' => $veterinarians['QUIROGA'],
        ]);

        $this->command?->info(sprintf(
            'VeterinaryCatalogSeeder: %d profesionales cargados.',
            count($veterinarians)
        ));
    }


    /**
     * ADR-29 / ADR-38: the file carries identity, never an institution. `cuit` is the person;
     * `billing_cuit` the entity they invoice under when it is not themselves.
     *
     * @return array<string, int> Veterinarian ids keyed by a stable seed alias.
     */
    private function seedVeterinarians(int $companyId): array
    {
        $definitions = [
            // Uses the portal from inside the system: has a login account.
            'ARANGUREN' => [
                'name' => 'Dr. Fernando Aranguren',
                'license_number' => 'MP 4582',
                'cuit' => '20315678901',
                'billing_cuit' => '30715554441',
                'accreditation_code' => 'SENASA-AC-11204',
                'phone' => '+54 249 415-6677',
                'email' => 'faranguren@ganadero.com',
            ],
            // External professional: operates offline and sends results over WhatsApp.
            'SOSA' => [
                'name' => 'Dra. Mariela Sosa',
                'license_number' => 'MP 6710',
                'cuit' => '27314567892',
                'accreditation_code' => 'SENASA-AC-20988',
                'phone' => '+54 2281 40-1122',
                'email' => 'mariela.sosa@vetsur.com.ar',
            ],
            'QUIROGA' => [
                'name' => 'Dr. Hernán Quiroga',
                'license_number' => 'MP 3391',
                'cuit' => '20309876548',
                'accreditation_code' => 'SENASA-AC-08877',
                'phone' => '+54 2954 46-3344',
                'email' => 'hquiroga@ruraldelsur.com.ar',
            ],
            // Colleague of Aranguren at Tandil: same centre, own licence, own login. She is the
            // counterparty that ADR-24 assumes exists — somebody at the receiving institution who
            // counts the tubes — and she is also the proof that Case 7 holds: an act is signed by
            // the professional it was issued to, never by whoever shares their letterhead.
            'ROLDAN' => [
                'name' => 'Dra. Cecilia Roldán',
                'license_number' => 'MP 5127',
                'cuit' => '27287654338',
                'billing_cuit' => '30715554441',
                'accreditation_code' => 'SENASA-AC-11890',
                'phone' => '+54 249 415-2201',
                'email' => 'croldan@labtandil.com.ar',
            ],
            // Virgin professional account: clean slate for portal testing without pre-existing
            // protocols or pre-assigned batches.
            'LAVERDE' => [
                'name' => 'Dr. Anthony Laverde',
                'license_number' => 'MP 8840',
                'cuit' => '20389912345',
                'billing_cuit' => '20389912345',
                'accreditation_code' => 'SENASA-AC-30120',
                'phone' => '+54 9 11 5555-0199',
                'email' => 'alaverde@ganadero.com',
            ],
        ];

        $ids = [];

        foreach ($definitions as $alias => $attributes) {
            $model = Veterinarian::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $companyId, 'license_number' => $attributes['license_number']],
                $attributes + ['company_id' => $companyId, 'is_active' => true]
            );

            $ids[$alias] = (int) $model->id;
        }

        return $ids;
    }

    /**
     * ADR-7: the portal role lives on the existing `company_user` pivot, and the catalogue row
     * is linked to the account so the professional can log in through the system itself.
     */
    private function seedPortalUser(int $companyId, int $veterinarianId, string $email, string $name): void
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => bcrypt('secret123'),
            ]
        );

        $alreadyLinked = DB::table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyLinked) {
            DB::table('company_user')
                ->where('company_id', $companyId)
                ->where('user_id', $user->id)
                ->update(['role' => 'veterinarian', 'updated_at' => now()]);
        } else {
            DB::table('company_user')->insert([
                'company_id' => $companyId,
                'user_id' => $user->id,
                'role' => 'veterinarian',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Veterinarian::withoutGlobalScopes()
            ->where('id', $veterinarianId)
            ->update(['user_id' => $user->id]);
    }

    /**
     * @param array<string, int> $veterinarians
     */
    private function seedBatchAssignments(int $companyId, array $veterinarians): void
    {
        $batches = Batch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->limit(4)
            ->pluck('id')
            ->all();

        if ($batches === []) {
            $this->command?->warn('VeterinaryCatalogSeeder: no hay lotes cargados; se omiten las asignaciones.');

            return;
        }

        $aliases = array_values($veterinarians);
        $assignedAt = now()->subDays(20)->toDateString();

        foreach ($batches as $index => $batchId) {
            VeterinarianBatchAssignment::withoutGlobalScopes()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'veterinarian_id' => $aliases[$index % count($aliases)],
                    'batch_id' => (int) $batchId,
                    'assigned_at' => $assignedAt,
                ],
                ['unassigned_at' => null]
            );
        }
    }
}
