<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

use App\Http\Requests\Concerns\ScopesToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A transfer between two warehouses of the company (DD7). Whether each warehouse is active and how
 * much may move are domain rules, checked with the balances locked.
 */
class StoreStockTransferRequest extends FormRequest
{
    use ScopesToActiveCompany;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from_warehouse_id' => ['required', 'integer'],
            'to_warehouse_id' => ['required', 'integer'],
            'occurred_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('company_id', $this->companyId())],
            'lines.*.input_quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.input_unit_id' => ['nullable', 'integer'],
            'lines.*.input_presentation_id' => ['nullable', 'integer'],
            'lines.*.semen_lot_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'from_warehouse_id.required' => 'Elegí el almacén de origen.',
            'to_warehouse_id.required' => 'Elegí el almacén de destino.',
            'lines.required' => 'La transferencia no tiene renglones.',
            'lines.*.item_id.exists' => 'El ítem no existe en esta empresa.',
            'lines.*.input_quantity.gt' => 'La cantidad tiene que ser mayor que cero.',
        ];
    }
}
