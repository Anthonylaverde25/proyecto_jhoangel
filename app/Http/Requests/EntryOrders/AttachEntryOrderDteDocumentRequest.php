<?php

declare(strict_types=1);

namespace App\Http\Requests\EntryOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The paper of head that moved without it: its number, date and the head it declares.
 */
final class AttachEntryOrderDteDocumentRequest extends FormRequest
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
            'dte_number' => 'required|string|max:40',
            'dte_date' => 'required|date|before_or_equal:today',
            'observations' => 'nullable|string|max:2000',
            'head_count' => 'required|integer|min:1|max:5000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dte_number.required' => 'Falta el número de DTE.',
            'dte_date.required' => 'Falta la fecha del DTE.',
            'dte_date.before_or_equal' => 'La fecha del DTE no puede ser futura.',
            'head_count.required' => 'Faltan las cabezas que declara el DTE.',
            'head_count.min' => 'El DTE tiene que declarar al menos una cabeza.',
        ];
    }
}
