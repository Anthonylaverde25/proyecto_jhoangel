<?php

declare(strict_types=1);

namespace App\Http\Requests\FiscalDocuments;

use Illuminate\Foundation\Http\FormRequest;

class ReadFiscalDocumentImageRequest extends FormRequest
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
            'document' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,avif,pdf', 'max:20480'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.required' => 'Elegí la imagen o el PDF del comprobante.',
            'document.mimes' => 'El comprobante tiene que ser una imagen (PNG, JPG, WEBP, AVIF) o un PDF.',
            'document.max' => 'El archivo no puede pesar más de 20 MB.',
        ];
    }
}
