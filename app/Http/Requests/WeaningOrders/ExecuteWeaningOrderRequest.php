<?php

declare(strict_types=1);

namespace App\Http\Requests\WeaningOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Ejecutar orden": when the weaning happened and, per calf, what the desk declares for it — weight
 * and notes, and the C/S when the order left the category for the chute. Whether a pair fits the
 * calf is checked by the use case, like on a sheet. `caravan_ids` weans only those pending calves
 * ("Registrar destetadas"): the rest stay pending and the order is left PARTIAL. `destinations`,
 * each calf's `destination_key` and `weaning_type` declare what happened when it differs from the
 * plan.
 */
final class ExecuteWeaningOrderRequest extends FormRequest
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
            'weaning_date' => 'sometimes|date|before_or_equal:today',
            'caravan_ids' => 'sometimes|array|min:1',
            'caravan_ids.*' => 'integer|distinct',
            'weaning_type' => 'nullable|string|in:TRADITIONAL,ANTICIPATED,EARLY',
            'destinations' => 'sometimes|array',
            'destinations.*.key' => 'required|string|max:255',
            'destinations.*.label' => 'nullable|string|max:255',
            'destinations.*.target_batch_id' => 'nullable|integer',
            'destinations.*.new_batch_name' => 'nullable|string|max:255',
            'destinations.*.is_confined' => 'nullable|boolean',
            'destinations.*.stays_in_place' => 'nullable|boolean',
            'animals.*.destination_key' => 'nullable|string|max:255',
            'animals' => 'sometimes|array',
            'animals.*.caravan_id' => 'required|integer',
            'animals.*.weight' => 'nullable|numeric|gt:0|max:2000',
            'animals.*.observations' => 'nullable|string|max:500',
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
            'weaning_date.before_or_equal' => 'La fecha del destete no puede ser futura.',
            'animals.*.weight.gt' => 'El peso tiene que ser mayor a cero.',
            'animals.*.category_id.exists' => 'La categoría elegida no existe.',
            'animals.*.subcategory_id.exists' => 'La subcategoría elegida no existe.',
        ];
    }
}
