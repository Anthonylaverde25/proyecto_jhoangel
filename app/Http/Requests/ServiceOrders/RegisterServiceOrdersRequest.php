<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceOrders;

use Illuminate\Foundation\Http\FormRequest;

class RegisterServiceOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'service_method'                  => 'required|string|in:NATURAL,AI',
            'start_date'                      => 'required|date',
            'end_date'                        => 'required|date',
            'observations'                    => 'nullable|string|max:1000',
            'lots'                            => 'required|array|min:1',
            'lots.*.batch_id'                 => 'required|integer|distinct',
            'lots.*.female_caravan_ids'       => 'nullable|array',
            'lots.*.female_caravan_ids.*'     => 'integer',
            'lots.*.bulls'                    => 'nullable|array',
            'lots.*.bulls.*.male_caravan_id'  => 'required|integer',
            'lots.*.bulls.*.origin_batch_id'  => 'required|integer',
            'lots.*.bulls.*.joined_at'        => 'required|date',
            'lots.*.bulls.*.retired_at'       => 'required|date',
            'lots.*.bulls.*.destination_batch_id' => 'required|integer',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lots.*.bulls.*.origin_batch_id.required' => 'Indicá de qué lote vino el toro.',
            'lots.*.bulls.*.destination_batch_id.required' => 'Indicá a qué lote fue el toro.',
            'lots.*.bulls.*.joined_at.required' => 'Indicá cuándo entró el toro.',
            'lots.*.bulls.*.retired_at.required' => 'Indicá cuándo salió el toro.',
        ];
    }
}
