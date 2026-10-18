<?php

declare(strict_types=1);

namespace App\Http\Requests\DeliveryNotes;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:20'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ids.required' => 'Marcá al menos un remito.',
            'ids.min' => 'Marcá al menos un remito.',
        ];
    }
}
