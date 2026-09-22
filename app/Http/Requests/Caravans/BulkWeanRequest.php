<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use Illuminate\Foundation\Http\FormRequest;

class BulkWeanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'new_batch'                     => ['nullable', 'array'],
            'new_batch.name'                => ['required_with:new_batch', 'string', 'max:255'],
            'new_batch.farm_id'             => ['nullable', 'integer', 'exists:farms,id'],
            'new_batch.activity_id'         => ['nullable', 'integer', 'exists:activities,id'],
            'new_batch.batch_type_id'       => ['nullable', 'integer', 'exists:batch_types,id'],
            'weanings'                      => ['required', 'array', 'min:1'],
            'weanings.*.caravan_id'         => ['required', 'integer', 'exists:caravans,id'],
            'weanings.*.target_batch_id'    => ['required_without:new_batch', 'nullable', 'integer', 'exists:batches,id'],
            'weanings.*.weaning_date'       => ['required', 'date', 'date_format:Y-m-d'],
            'weanings.*.weaning_weight'     => ['required', 'numeric', 'min:0.1'],
            'weanings.*.new_category'       => ['nullable', 'string'],
            'weanings.*.new_category_id'    => ['nullable', 'integer', 'exists:animal_categories,id'],
            'weanings.*.new_subcategory_id' => ['nullable', 'integer', 'exists:animal_subcategories,id'],
            'weanings.*.notes'              => ['nullable', 'string', 'max:500'],
        ];
    }
}
