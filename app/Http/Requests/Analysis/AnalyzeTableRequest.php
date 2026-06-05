<?php

declare(strict_types=1);

namespace App\Http\Requests\Analysis;

use Illuminate\Foundation\Http\FormRequest;

class AnalyzeTableRequest extends FormRequest
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
            'image' => 'required|image|max:10240', // Max 10MB
        ];
    }
}
