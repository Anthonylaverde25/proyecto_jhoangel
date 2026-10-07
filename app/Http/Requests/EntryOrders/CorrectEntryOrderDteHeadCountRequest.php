<?php

declare(strict_types=1);

namespace App\Http\Requests\EntryOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The head a DTE really declares, and why it was loaded wrong.
 */
final class CorrectEntryOrderDteHeadCountRequest extends FormRequest
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
            'head_count' => 'required|integer|min:1|max:5000',
            'reason' => 'required|string|max:1000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'head_count.required' => 'Faltan las cabezas que declara el DTE.',
            'head_count.min' => 'El DTE tiene que declarar al menos una cabeza.',
            'reason.required' => 'Indicá por qué se corrigen las cabezas.',
        ];
    }
}
