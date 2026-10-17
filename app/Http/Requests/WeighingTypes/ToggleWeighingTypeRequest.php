<?php

declare(strict_types=1);

namespace App\Http\Requests\WeighingTypes;

use Illuminate\Foundation\Http\FormRequest;

class ToggleWeighingTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }
}
