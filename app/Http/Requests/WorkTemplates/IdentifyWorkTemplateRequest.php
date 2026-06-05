<?php

declare(strict_types=1);

namespace App\Http\Requests\WorkTemplates;

use Illuminate\Foundation\Http\FormRequest;

class IdentifyWorkTemplateRequest extends FormRequest
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
            'document' => 'required|file|mimes:pdf,png,jpg,jpeg,tiff|max:20480',
            'provider' => 'nullable|string|in:azure,google',
        ];
    }
}
