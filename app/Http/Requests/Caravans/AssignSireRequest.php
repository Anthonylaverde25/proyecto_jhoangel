<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use App\Core\Enums\SireIdentificationMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class AssignSireRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'father_id'              => 'required|integer|exists:caravans,id',
            'identification_method'  => ['required', 'string', new Enum(SireIdentificationMethod::class)],
            'sire_notes'             => 'nullable|string|max:500',
        ];
    }

    /**
     * Custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'father_id.required'             => 'The sire (father) ID is required.',
            'father_id.exists'               => 'The specified sire does not exist.',
            'identification_method.required' => 'Sire identification method is required.',
            'identification_method.Enum'     => 'Invalid identification method. Valid values: operational, phenotype, lab_genetic.',
        ];
    }
}
