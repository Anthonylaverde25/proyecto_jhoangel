<?php

declare(strict_types=1);

namespace App\Http\Requests\Veterinary;

use Illuminate\Foundation\Http\FormRequest;

class StoreVeterinarianRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'license_number' => ['required', 'string', 'max:50'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            // ADR-38: validated as a real CUIT by the value object, not by a regex here.
            'cuit' => ['nullable', 'string', 'max:13'],
            'billing_cuit' => ['nullable', 'string', 'max:13'],
            'accreditation_code' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
