<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignExternalCaravansToOwnBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'caravan_ids' => ['required', 'array', 'min:1'],
            'caravan_ids.*' => ['required', 'integer', 'exists:caravans,id'],
            'target_batch_id' => ['required', 'integer', 'exists:batches,id'],
            'entry_date' => ['nullable', 'date'],
            'observations' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'caravan_ids.required' => 'Debe enviar una lista de identificadores de caravanas.',
            'caravan_ids.min' => 'Debe seleccionar al menos una caravana.',
            'target_batch_id.required' => 'Debe especificar el lote propio de destino.',
            'target_batch_id.exists' => 'El lote propio de destino no existe.',
        ];
    }
}
