<?php

declare(strict_types=1);

namespace App\Http\Requests\WeaningOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The same shape as an order, minus `issue` (it is born executed), with the weaning date required
 * and never in the future — it is the day the calves were weaned — and the chute data of each calf.
 */
final class RegisterWeaningRequest extends FormRequest
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
        $rules = (new EmitWeaningOrderRequest())->rules();
        unset($rules['issue']);

        return [
            ...$rules,
            'weaning_date' => 'required|date|before_or_equal:today',
            // Optional, as on the DEST-01 sheet: a calf not weighed keeps its last weight.
            'animals.*.weight' => 'nullable|numeric|gt:0|max:2000',
            'animals.*.observations' => 'nullable|string|max:500',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...(new EmitWeaningOrderRequest())->messages(),
            'weaning_date.before_or_equal' => 'La fecha del destete no puede ser futura.',
            'animals.*.weight.gt' => 'El peso tiene que ser mayor a cero.',
            'animals.*.weight.max' => 'El peso no puede superar los 2000 kg.',
        ];
    }
}
