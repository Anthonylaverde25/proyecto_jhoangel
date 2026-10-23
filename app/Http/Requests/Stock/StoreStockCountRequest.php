<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

use App\Http\Requests\Concerns\ScopesToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockCountRequest extends FormRequest
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
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('company_id', $this->companyId())],
            'warehouse_id' => ['nullable', 'integer'],
            'system_quantity_seen' => ['required', 'decimal:0,4'],
            'counted_quantity' => ['required', 'decimal:0,4', 'min:0'],
            'input_unit_id' => ['nullable', 'integer'],
            'input_presentation_id' => ['nullable', 'integer'],
            'semen_lot_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['required', 'date'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
