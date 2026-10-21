<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape of a "Registrar muerte" load. Whether each animal can die (alive, not held by an open
 * order, born before that day) is checked by the use case, animal by animal.
 */
final class RegisterDeathRequest extends FormRequest
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
            'caravan_ids' => 'required|array|min:1',
            'caravan_ids.*' => 'required|integer|distinct',
            'death_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'death_cause_id' => 'required|integer',
            'observations' => 'nullable|string|max:500',
            'death_batch_id' => 'nullable|integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'caravan_ids.required' => 'Elegí al menos un animal.',
            'death_date.required' => 'Indicá la fecha de la muerte.',
            'death_date.before_or_equal' => 'La fecha de la muerte no puede ser futura.',
            'death_cause_id.required' => 'Elegí la causa de la muerte.',
        ];
    }
}
