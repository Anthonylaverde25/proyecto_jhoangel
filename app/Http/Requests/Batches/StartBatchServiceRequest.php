<?php

declare(strict_types=1);

namespace App\Http\Requests\Batches;

use Illuminate\Foundation\Http\FormRequest;

class StartBatchServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service_batch_name'            => 'nullable|string|max:255',
            'male_caravan_ids'              => 'required|array|min:1',
            'male_caravan_ids.*'            => 'required|integer|exists:caravans,id',
            'selected_female_caravan_ids'   => 'nullable|array|min:1',
            'selected_female_caravan_ids.*' => 'required|integer|exists:caravans,id',
            'target_bull_ratio'             => 'nullable|numeric|min:0.1|max:50',
            'planned_start_date'            => 'required|date',
            'planned_end_date'              => 'nullable|date|after_or_equal:planned_start_date',
            'service_type'                  => 'sometimes|string|in:single,multi,rotation',
            'is_controlled_service'         => 'sometimes|boolean',
            'female_sire_assignments'       => 'nullable|array',
            'female_sire_assignments.*.female_caravan_id'        => 'required_with:female_sire_assignments|integer|exists:caravans,id',
            'female_sire_assignments.*.assigned_male_caravan_id' => 'required_with:female_sire_assignments|integer|exists:caravans,id',
            'observations'                  => 'nullable|string|max:1000',
        ];
    }
}
