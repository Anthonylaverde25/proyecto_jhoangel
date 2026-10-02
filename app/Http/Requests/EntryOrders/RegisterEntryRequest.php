<?php

declare(strict_types=1);

namespace App\Http\Requests\EntryOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Registrar ingreso": the troop of an order plus its DTE under `dte`, and optionally the reason
 * to close it incomplete at once when the DTE brings fewer head than were bought.
 */
final class RegisterEntryRequest extends FormRequest
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
        $rules = (new StoreEntryOrderRequest())->rules();
        unset($rules['confirm']);

        return [
            ...$rules,
            'dte' => 'required|array',
            ...LoadEntryOrderDteRequest::dteRules('dte.'),
            'close_incomplete_reason' => 'nullable|string|max:1000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...(new StoreEntryOrderRequest())->messages(),
            ...LoadEntryOrderDteRequest::dteMessages('dte.'),
        ];
    }
}
