<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape of a "Caravanear crías" load. Whether each tag is free and each calf still untagged is
 * checked by the use case, row by row.
 */
final class TagCalvesRequest extends FormRequest
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
            'tagged_at' => 'required|date_format:Y-m-d|before_or_equal:today',
            'items' => 'required|array|min:1',
            'items.*.caravan_id' => 'required|integer|distinct',
            'items.*.identification' => 'required|string|max:100',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tagged_at.required' => 'Indicá la fecha en que se caravanearon.',
            'tagged_at.before_or_equal' => 'La fecha de caravaneo no puede ser futura.',
            'items.required' => 'Cargá la caravana de al menos una cría.',
            'items.*.identification.required' => 'Falta la caravana.',
        ];
    }
}
