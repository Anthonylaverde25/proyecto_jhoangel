<?php

declare(strict_types=1);

namespace App\Http\Requests\EntryOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Editar distribución": the whole set of destinations of the order, replacing the one it had, and
 * the name of the transit batch when something is left without a destination and the order has none.
 */
final class RedistributeEntryOrderRequest extends FormRequest
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
            ...StoreEntryOrderRequest::destinationRules(),
            'destinations' => 'present|array|max:20',
            'transit_batch_name' => 'nullable|string|max:255',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destinations.*.destination_batch_id.exists' => 'El lote de destino no existe.',
            'destinations.*.sex_scope.in' => 'El destino es para toda la categoría (ALL), los machos (M) o las hembras (H).',
        ];
    }
}
