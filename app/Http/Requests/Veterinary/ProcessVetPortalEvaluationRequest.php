<?php

declare(strict_types=1);

namespace App\Http\Requests\Veterinary;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessVetPortalEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'batch_id' => ['required', 'integer', 'exists:batches,id'],
            'protocol_number' => ['required', 'string', 'max:100'],
            'sample_date' => ['required', 'date'],
            'result_date' => ['required', 'date', 'after_or_equal:sample_date'],
            'observations' => ['nullable', 'string', 'max:2000'],

            'bulls' => ['required', 'array', 'min:1'],
            'bulls.*.caravan_id' => ['required', 'integer', 'exists:caravans,id'],
            'bulls.*.scrotal_circumference_cm' => ['nullable', 'numeric', 'min:15', 'max:60'],
            'bulls.*.body_condition_score' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'bulls.*.aplomo_notes' => ['nullable', 'string', 'max:1000'],
            'bulls.*.libido' => ['nullable', Rule::in(['BAJA', 'MEDIA', 'ALTA', 'MUY_ALTA'])],
            'bulls.*.observations' => ['nullable', 'string', 'max:1000'],

            'bulls.*.samples' => ['nullable', 'array'],
            'bulls.*.samples.*.pathogen_id' => ['required', 'integer', 'exists:pathogens,id'],
            'bulls.*.samples.*.sample_type' => ['required', Rule::in(['PREPUCE_SCRAPE', 'BLOOD_SEROLOGY', 'SEMEN_CULTURE', 'TUBERCULIN_TEST'])],
            'bulls.*.samples.*.sample_round' => ['required', 'integer', 'min:1', 'max:10'],
            'bulls.*.samples.*.status' => ['required', Rule::in(['PENDING_RESULTS', 'NEGATIVE_CLEARED', 'POSITIVE_DETECTED'])],
            'bulls.*.samples.*.tube_number' => ['nullable', 'string', 'max:50'],
            'bulls.*.samples.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
