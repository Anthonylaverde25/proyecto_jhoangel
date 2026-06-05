<?php

declare(strict_types=1);

namespace App\Http\Requests\Analysis;

use Illuminate\Foundation\Http\FormRequest;

class AnalyzeDocumentRequest extends FormRequest
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
        if ($this->isMethod('get')) {
            return [];
        }

        return [
            'document'     => 'required|file|mimes:pdf,png,jpg,jpeg,tiff|max:20480', // Max 20MB
            'provider'     => 'nullable|string|in:azure,google',
            'target_model' => 'nullable|string|max:255',
        ];
    }
}
