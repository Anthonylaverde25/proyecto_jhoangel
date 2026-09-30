<?php

declare(strict_types=1);

namespace App\Http\Requests\TransferOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The same shape as an order, minus `issue` (it is born executed), and with the date of the
 * movement required and never in the future: it is the day the animals actually moved.
 */
final class RegisterTransferRequest extends FormRequest
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
        $rules = (new EmitTransferOrderRequest())->rules();
        unset($rules['issue']);

        return [
            ...$rules,
            'movement_date' => 'required|date|before_or_equal:today',

            // What was measured at the chute. Empty means "no change".
            'animals.*.current_weight' => 'nullable|numeric|gt:0|max:2000',
            'animals.*.teeth' => 'nullable|string|in:DL,2D,4D,6D,8D',
            'animals.*.category_id' => 'nullable|integer|exists:animal_categories,id',
            // Read only together with category_id; that it belongs to it is checked by the use case.
            'animals.*.subcategory_id' => 'nullable|integer|exists:animal_subcategories,id',
            'animals.*.observations' => 'nullable|string|max:500',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...(new EmitTransferOrderRequest())->messages(),
            'movement_date.required' => 'Indicá la fecha en que se hizo el movimiento.',
            'movement_date.before_or_equal' => 'La fecha del movimiento no puede ser futura.',
            'animals.*.current_weight.gt' => 'El peso tiene que ser mayor a cero.',
            'animals.*.current_weight.max' => 'El peso no puede superar los 2000 kg.',
            'animals.*.teeth.in' => 'La dentición tiene que ser DL, 2D, 4D, 6D u 8D (boca llena).',
            'animals.*.category_id.exists' => 'La categoría elegida no existe.',
            'animals.*.subcategory_id.exists' => 'La subcategoría elegida no existe.',
        ];
    }
}
