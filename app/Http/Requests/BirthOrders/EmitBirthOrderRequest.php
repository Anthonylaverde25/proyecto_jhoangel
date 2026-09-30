<?php

declare(strict_types=1);

namespace App\Http\Requests\BirthOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. Whether the females are pregnant and free of another birth order is answered by
 * BirthOrderRosterBuilder.
 */
final class EmitBirthOrderRequest extends FormRequest
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
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'responsable' => 'nullable|string|max:255',
            'observations' => 'nullable|string|max:2000',
            // false (default) saves a draft; true creates the order already issued.
            'issue' => 'sometimes|boolean',
            'animals' => 'required|array|min:1',
            'animals.*.caravan_id' => 'required|integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'animals.required' => 'Elegí al menos una hembra preñada.',
            'animals.min' => 'Elegí al menos una hembra preñada.',
            'period_end.after_or_equal' => 'El período de parición termina antes de empezar.',
        ];
    }
}
