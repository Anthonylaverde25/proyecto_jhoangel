<?php

declare(strict_types=1);

namespace App\Http\Requests\DeliveryNotes;

use Illuminate\Foundation\Http\FormRequest;

class ResolveDiscrepancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['resolution' => ['required', 'string', 'max:1000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['resolution.required' => 'Contá cómo se resolvió con el proveedor.'];
    }
}
