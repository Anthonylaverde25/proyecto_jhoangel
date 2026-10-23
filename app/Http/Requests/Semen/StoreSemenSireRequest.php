<?php

declare(strict_types=1);

namespace App\Http\Requests\Semen;

use Illuminate\Foundation\Http\FormRequest;

/** Shapes only; the sire's rules are domain rules answered by field (SS1–SS5). */
class StoreSemenSireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:100'],
            'registry_code' => ['nullable', 'string', 'max:40'],
            'breed_id' => ['nullable', 'integer', 'exists:breeds,id'],
            'stud_name' => ['nullable', 'string', 'max:100'],
            'caravan_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.max' => 'El nombre tiene hasta 100 caracteres.',
            'registry_code.max' => 'El registro tiene hasta 40 caracteres.',
            'breed_id.exists' => 'La raza no existe.',
        ];
    }
}
