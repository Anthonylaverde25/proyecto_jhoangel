<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehouses;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Code and name of a warehouse (DD1). Their format and uniqueness are domain rules (R1), so the
 * screen gets them per field in Spanish.
 */
class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'Indicá el código.',
            'code.max' => 'El código tiene hasta 32 caracteres.',
            'name.required' => 'Indicá el nombre.',
            'name.max' => 'El nombre tiene hasta 100 caracteres.',
        ];
    }
}
