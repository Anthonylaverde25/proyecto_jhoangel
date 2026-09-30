<?php

declare(strict_types=1);

namespace App\Http\Requests\TransferOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. Whether the batches exist, belong to the destination activity and hold the
 * animals is answered by EmitTransferOrderUseCase.
 */
final class EmitTransferOrderRequest extends FormRequest
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
            'source_batch_id' => 'required|integer',
            'destination_activity_id' => 'required|integer',
            'destination_mode' => 'required|string|in:single,per_animal',
            // Absent means KEEP: an order says the category changes only when somebody says so.
            'category_mode' => 'sometimes|string|in:KEEP,DECLARED,AT_CHUTE',
            'movement_date' => 'required|date',
            'responsable' => 'nullable|string|max:255',
            'observations' => 'nullable|string|max:2000',
            // false (default) saves a draft; true creates the order already issued.
            'issue' => 'sometimes|boolean',

            'destinations' => 'present|array',
            'destinations.*.key' => 'required|string|max:255',
            'destinations.*.label' => 'nullable|string|max:255',
            'destinations.*.target_batch_id' => 'nullable|integer',
            'destinations.*.new_batch_name' => 'nullable|string|max:255',
            'destinations.*.new_batch_type_id' => 'nullable|integer',
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
            'destination_activity_id.required' => 'Declará la actividad de destino del movimiento.',
            'animals.required' => 'Elegí al menos un animal.',
            'animals.min' => 'Elegí al menos un animal.',
        ];
    }
}
