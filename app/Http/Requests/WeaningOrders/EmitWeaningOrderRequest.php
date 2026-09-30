<?php

declare(strict_types=1);

namespace App\Http\Requests\WeaningOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. Whether the calves are at foot and the batches are weaning batches is answered by
 * WeaningOrderRosterBuilder.
 */
final class EmitWeaningOrderRequest extends FormRequest
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
            'destination_mode' => 'required|string|in:single,per_animal',
            // Absent means KEEP: an order says the category changes only when somebody says so.
            'category_mode' => 'sometimes|string|in:KEEP,DECLARED,AT_CHUTE',
            'weaning_date' => 'required|date',
            'weaning_type' => 'nullable|string|in:TRADITIONAL,ANTICIPATED,EARLY',
            'responsable' => 'nullable|string|max:255',
            'observations' => 'nullable|string|max:2000',
            // false (default) saves a draft; true creates the order already issued.
            'issue' => 'sometimes|boolean',

            'destinations' => 'present|array',
            'destinations.*.key' => 'required|string|max:255',
            'destinations.*.label' => 'nullable|string|max:255',
            'destinations.*.target_batch_id' => 'nullable|integer',
            'destinations.*.new_batch_name' => 'nullable|string|max:255',
            'destinations.*.is_confined' => 'nullable|boolean',

            'animals' => 'required|array|min:1',
            'animals.*.caravan_id' => 'required|integer',
            'animals.*.destination_key' => 'nullable|string|max:255',
            'animals.*.target_category_id' => 'nullable|integer',
            'animals.*.target_subcategory_id' => 'nullable|integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'animals.required' => 'Elegí al menos una cría.',
            'animals.min' => 'Elegí al menos una cría.',
            'weaning_date.required' => 'Indicá la fecha del destete.',
            'weaning_type.in' => 'El tipo de destete tiene que ser Tradicional, Anticipado o Precoz.',
        ];
    }
}
