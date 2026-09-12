<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\User;
use App\Models\VeterinarianBatchAssignment;

/**
 * ADR-7: the portal is fail-closed. Being logged in is not enough; the account must hold the
 * `veterinarian` role in this company AND be linked to a catalogue row.
 */
class VeterinaryPortalAuthorizationTest extends VeterinaryTestCase
{
    public function test_an_anonymous_request_without_a_token_is_rejected(): void
    {
        $this->getJson($this->url('/veterinary-portal/session'))->assertStatus(401);
    }

    public function test_a_plain_operator_cannot_reach_the_portal(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->getJson($this->url('/veterinary-portal/session'))
            ->assertStatus(403);
    }

    public function test_the_role_alone_is_not_enough_without_a_catalogue_row(): void
    {
        $stranger = User::create([
            'name' => 'Usuario Sin Matrícula',
            'email' => 'sin-matricula@prueba.com',
            'password' => bcrypt('secret123'),
        ]);

        $this->linkUserToCompany($stranger, (int) $this->company->id, 'veterinarian');

        $this->actingAs($stranger, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->getJson($this->url('/veterinary-portal/session'))
            ->assertStatus(403);
    }

    public function test_a_veterinarian_cannot_load_results_on_an_unassigned_batch(): void
    {
        $vetUser = User::where('email', 'faranguren@ganadero.com')->firstOrFail();
        $vet = $this->veterinarian('MP 4582');

        $assignedBatchIds = VeterinarianBatchAssignment::withoutGlobalScopes()
            ->where('veterinarian_id', $vet->id)
            ->whereNull('unassigned_at')
            ->pluck('batch_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $foreignBatchId = (int) \App\Models\Batch::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereNotIn('id', $assignedBatchIds)
            ->value('id');

        $this->assertGreaterThan(0, $foreignBatchId);

        $bull = \App\Models\Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'AUTHZ-TR-01',
            'sex' => 'M',
            'batch_id' => $foreignBatchId,
        ]);

        $this->actingAs($vetUser, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson($this->url('/veterinary-portal/evaluations'), [
                'batch_id' => $foreignBatchId,
                'protocol_number' => 'AUTHZ-2026-001',
                'sample_date' => now()->subDay()->toDateString(),
                'result_date' => now()->toDateString(),
                'bulls' => [['caravan_id' => $bull->id, 'scrotal_circumference_cm' => 36.0]],
            ])
            ->assertStatus(422);
    }
}
