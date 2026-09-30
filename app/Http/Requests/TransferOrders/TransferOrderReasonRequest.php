<?php

declare(strict_types=1);

namespace App\Http\Requests\TransferOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Closing incomplete and cancelling both demand a reason: it is what stays in the history.
 */
final class TransferOrderReasonRequest extends FormRequest
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
            'reason' => 'required|string|min:3|max:2000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Indicá el motivo.',
            'reason.min' => 'El motivo es demasiado corto.',
        ];
    }
}
