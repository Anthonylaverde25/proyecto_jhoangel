<?php

declare(strict_types=1);

namespace App\Http\Requests\ExitOrders;

use Illuminate\Foundation\Http\FormRequest;

/** "Emitir egreso" and its edit: buyer, planned date and the animals. */
class IssueExitOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'buyer_partner_id' => ['required', 'integer'],
            'exit_date' => ['required', 'date_format:Y-m-d'],
            'caravan_ids' => ['required', 'array', 'min:1'],
            'caravan_ids.*' => ['integer', 'distinct'],
            'responsable' => ['nullable', 'string', 'max:255'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'agreed_prices' => ['nullable', 'array'],
            'agreed_prices.*.caravan_id' => ['required', 'integer'],
            'agreed_prices.*.price_per_kg' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'buyer_partner_id.required' => 'Elegí el comprador.',
            'caravan_ids.required' => 'La orden necesita al menos un animal.',
            'caravan_ids.min' => 'La orden necesita al menos un animal.',
            'caravan_ids.*.distinct' => 'Ese animal está dos veces en la orden.',
        ];
    }
}
