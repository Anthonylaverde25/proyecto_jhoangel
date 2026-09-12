<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\Caravan;
use App\Models\VeterinaryDiagnosis;

/**
 * F15 regression. `BullClinicalHistoryController` filtered diagnoses against the string
 * 'ACTIVE', a value that does not exist in DiagnosisStatus, so every bull's clinical history
 * reported exactly zero active diagnoses no matter what was on file.
 */
class BullClinicalHistoryActiveDiagnosesTest extends VeterinaryTestCase
{
    public function test_a_bull_with_a_confirmed_positive_reports_one_active_diagnosis(): void
    {
        $diagnosis = VeterinaryDiagnosis::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('status', 'CONFIRMED_POSITIVE')
            ->firstOrFail();

        $caravan = Caravan::withoutGlobalScopes()->findOrFail($diagnosis->caravan_id);

        $response = $this->apiAs('GET', "/pre-service/bulls/{$caravan->id}/clinical-history");

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.metrics.active_diagnoses_count'));
    }

    public function test_a_resolved_diagnosis_does_not_count_as_active(): void
    {
        $diagnosis = VeterinaryDiagnosis::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('status', 'CONFIRMED_POSITIVE')
            ->firstOrFail();

        $caravanId = (int) $diagnosis->caravan_id;

        $before = (int) $this->apiAs('GET', "/pre-service/bulls/{$caravanId}/clinical-history")
            ->json('data.metrics.active_diagnoses_count');

        $diagnosis->update(['status' => 'RESOLVED', 'resolution_date' => now()->toDateString()]);

        $after = (int) $this->apiAs('GET', "/pre-service/bulls/{$caravanId}/clinical-history")
            ->json('data.metrics.active_diagnoses_count');

        $this->assertSame($before - 1, $after);
    }
}
