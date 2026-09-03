<?php

declare(strict_types=1);

namespace App\Http\Requests\Batches;

use Illuminate\Foundation\Http\FormRequest;

class CreateServiceBatchRequest extends FormRequest
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
            'name'                   => 'required|string|max:255',
            'female_category_id'     => 'required|integer|exists:animal_categories,id',
            'female_subcategory_id'  => 'nullable|integer|exists:animal_subcategories,id',
            'male_category_id'       => 'required|integer|exists:animal_categories,id',
            'female_caravan_ids'     => 'nullable|array',
            'female_caravan_ids.*'   => 'integer|exists:caravans,id',
            'male_caravan_ids'       => 'nullable|array',
            'male_caravan_ids.*'     => 'integer|exists:caravans,id',
            'farm_id'                => 'nullable|integer|exists:farms,id',
            'target_bull_ratio'      => 'nullable|numeric|min:0|max:100',
            'planned_start_date'     => 'nullable|date',
            'planned_end_date'       => 'nullable|date|after_or_equal:planned_start_date',
            'notes'                  => 'nullable|string',
            'observaciones'          => 'nullable|string',
            'auto_create_service_order' => 'nullable|boolean',
        ];
    }
}
