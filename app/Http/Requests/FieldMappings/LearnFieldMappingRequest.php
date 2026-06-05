<?php

declare(strict_types=1);

namespace App\Http\Requests\FieldMappings;

use Illuminate\Foundation\Http\FormRequest;

class LearnFieldMappingRequest extends FormRequest
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
            'alias_name'   => 'required|string|max:255',
            'target_field' => 'required|string|max:255',
            'target_model' => 'required|string|max:255',
        ];
    }
}
