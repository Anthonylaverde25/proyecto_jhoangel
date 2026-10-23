<?php

declare(strict_types=1);

namespace App\Http\Requests\Mcp;

use Illuminate\Foundation\Http\FormRequest;

class IssueMcpConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The upper bound of the validity lives in the use case, against configuration.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'ttl_days' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Poné un nombre para reconocer la conexión (por ejemplo, "Claude oficina").',
            'name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'ttl_days.required' => 'Elegí la vigencia de la conexión.',
        ];
    }
}
