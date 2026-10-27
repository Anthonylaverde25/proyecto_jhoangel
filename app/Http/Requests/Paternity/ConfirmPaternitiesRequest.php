<?php

declare(strict_types=1);

namespace App\Http\Requests\Paternity;

use App\Core\Enums\SireIdentificationMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ConfirmPaternitiesRequest extends FormRequest
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
            'confirmations' => 'required|array|min:1',
            'confirmations.*.calf_id' => 'required|integer',
            'confirmations.*.father_id' => 'required|integer',
            'confirmations.*.method' => ['required', 'string', new Enum(SireIdentificationMethod::class)],
            'confirmations.*.notes' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'confirmations.*.father_id.required' => 'Elegí el padre.',
            'confirmations.*.method.required' => 'Elegí cómo se identificó el padre.',
        ];
    }
}
