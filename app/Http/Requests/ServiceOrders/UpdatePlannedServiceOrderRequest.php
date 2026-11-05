<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceOrders;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlannedServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'start_date'         => 'required|date',
            'end_date'           => 'nullable|date|after_or_equal:start_date',
            'observations'       => 'nullable|string|max:1000',
            'male_caravan_ids'   => 'nullable|array',
            'male_caravan_ids.*' => 'integer',
        ];
    }
}
