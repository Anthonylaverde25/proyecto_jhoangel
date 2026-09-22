<?php

declare(strict_types=1);

namespace App\Http\Requests\Batches;

use Illuminate\Foundation\Http\FormRequest;

class ChangeBatchManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_confined' => 'required|boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_confined.required' => 'Indicá si el lote se maneja a corral o de forma extensiva.',
        ];
    }
}
