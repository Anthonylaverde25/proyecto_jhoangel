<?php

declare(strict_types=1);

namespace App\Http\Requests\BirthOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Ejecutar orden": what one round found, per pending female. Females without an outcome stay
 * pending for a later round.
 */
final class ExecuteBirthOrderRequest extends FormRequest
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
            'round_date' => 'sometimes|nullable|date|before_or_equal:today',
            'animals' => 'required|array|min:1',
            ...BirthFieldRules::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...BirthFieldRules::messages(),
            'round_date.before_or_equal' => 'La fecha de la recorrida no puede ser futura.',
            'animals.required' => 'Indicá el resultado de al menos un vientre.',
        ];
    }
}
