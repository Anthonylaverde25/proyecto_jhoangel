<?php

declare(strict_types=1);

namespace App\Http\Requests\TransferOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Ejecutar orden": when the movement happened and, per animal, the C/S the desk declares for it.
 *
 * Everything is optional so the endpoint keeps answering an empty body the way it always did:
 * executed today, with the categories the order declared. Whether a pair fits the animal is
 * checked by the use case, like on a sheet.
 */
final class ExecuteTransferOrderRequest extends FormRequest
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
            'movement_date' => 'sometimes|date|before_or_equal:today',
            'animals' => 'sometimes|array',
            'animals.*.caravan_id' => 'required|integer',
            'animals.*.category_id' => 'nullable|integer|exists:animal_categories,id',
            'animals.*.subcategory_id' => 'nullable|integer|exists:animal_subcategories,id',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'movement_date.before_or_equal' => 'La fecha del movimiento no puede ser futura.',
            'animals.*.category_id.exists' => 'La categoría elegida no existe.',
            'animals.*.subcategory_id.exists' => 'La subcategoría elegida no existe.',
        ];
    }
}
