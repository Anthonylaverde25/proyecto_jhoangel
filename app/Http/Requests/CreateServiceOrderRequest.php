<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateServiceOrderRequest extends FormRequest
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
            'batch_id'             => 'required|integer|exists:batches,id',
            'code'                 => 'required|string|max:255|unique:service_orders,code',
            'planned_start_date'   => 'required|date',
            'observations'         => 'nullable|string',
            'male_caravan_ids'     => 'required|array|min:1',
            'male_caravan_ids.*'   => 'required|integer|exists:caravans,id',
            'female_caravan_ids'   => 'required|array|min:1',
            'female_caravan_ids.*' => 'required|integer|exists:caravans,id',
            'service_type'                                        => 'sometimes|string|in:single,rotation,multi',
            'is_controlled_service'                               => 'sometimes|boolean',
            'female_sire_assignments'                             => 'required_if:is_controlled_service,true|array',
            'female_sire_assignments.*.female_caravan_id'          => 'required|integer|exists:caravans,id',
            'female_sire_assignments.*.assigned_male_caravan_id'   => 'required|integer|exists:caravans,id',
        ];
    }
}
