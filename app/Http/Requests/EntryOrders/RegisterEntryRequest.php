<?php

declare(strict_types=1);

namespace App\Http\Requests\EntryOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Registrar ingreso": the troop of an order plus its DTE under `dte` — the head it declares, the
 * day the animals entered and the animals received, one per caravan — and optionally the reason
 * to close it incomplete at once when fewer head arrived than were bought.
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
            ...ReceiveEntryOrderRequest::animalRules('dte.'),
            'dte.animals' => 'required|array|min:1|max:5000',
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
            'dte.animals.required' => 'Cargá las caravanas de los animales que ingresaron.',
            'dte.animals.min' => 'Cargá las caravanas de los animales que ingresaron.',
        ];
    }
}
