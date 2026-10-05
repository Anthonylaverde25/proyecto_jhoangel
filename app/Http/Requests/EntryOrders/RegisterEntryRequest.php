<?php

declare(strict_types=1);

namespace App\Http\Requests\EntryOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Registrar ingreso": the troop of an order plus its DTE under `dte`, with the day the animals
 * entered and the weight of each one if taken (the DTE and the animals arrive together), and
 * optionally the reason to close it incomplete at once when the DTE brings fewer head than were bought.
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

        // The animals already arrived: the troop is always complete.
        $rules = array_map(
            fn (array|string $rule) => is_array($rule)
                ? array_map(fn ($part) => $part === StoreEntryOrderRequest::WHEN_CONFIRMED ? 'required' : $part, $rule)
                : $rule,
            $rules
        );
        $rules['batch_name'] = 'nullable|required_if:batch_name_mode,CUSTOM|string|max:255';

        return [
            ...$rules,
            'dte' => 'required|array',
            ...LoadEntryOrderDteRequest::dteRules('dte.'),
            'dte.entered_at' => 'required|date|before_or_equal:today',
            'dte.animals.*.weight' => 'nullable|numeric|max:2000',
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
            'dte.entered_at.required' => 'Falta la fecha de ingreso.',
            'dte.entered_at.before_or_equal' => 'La fecha de ingreso no puede ser futura.',
        ];
    }
}
