<?php

declare(strict_types=1);

namespace App\Http\Requests\EntryOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The TRI of a DTE to read: a photo per page, or a PDF with all of them.
 */
final class ReadTriRequest extends FormRequest
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
            'documents' => 'required|array|min:1|max:10',
            'documents.*' => 'file|mimes:jpg,jpeg,png,webp,heic,pdf|max:15360',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'documents.required' => 'Adjuntá el TRI: una foto por hoja o un PDF.',
            'documents.max' => 'Hasta 10 archivos por TRI.',
            'documents.*.mimes' => 'El TRI tiene que ser una imagen (JPG, PNG, WEBP, HEIC) o un PDF.',
            'documents.*.max' => 'Cada archivo puede pesar hasta 15 MB.',
        ];
    }
}
