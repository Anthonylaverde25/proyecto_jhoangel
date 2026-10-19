<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

use App\Http\Requests\Concerns\ScopesToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Initial stock and consumptions share their shape. Initial stock declares a cost per line; a
 * consumption never does (it leaves at the average) and may carry a batch with its heads.
 */
abstract class StoreStockOperationRequest extends FormRequest
{
    use ScopesToActiveCompany;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $initial = $this->declaresCost();

        return [
            'occurred_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'warehouse_id' => ['nullable', 'integer'],  // the default when absent (DD6)
            'batch_id' => $initial ? ['prohibited'] : ['nullable', 'integer'],
            'heads_treated' => $initial ? ['prohibited'] : ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('company_id', $this->companyId())],
            'lines.*.input_quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.input_unit_id' => ['nullable', 'integer'],
            'lines.*.input_presentation_id' => ['nullable', 'integer'],
            'lines.*.input_unit_price' => $initial ? ['required', 'decimal:0,6', 'min:0'] : ['prohibited'],
        ];
    }

    /** Initial stock declares its cost; a consumption leaves at the average. */
    abstract protected function declaresCost(): bool;

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.required' => 'La carga no tiene renglones.',
            'lines.*.item_id.exists' => 'El ítem no existe en esta empresa.',
            'lines.*.input_quantity.gt' => 'La cantidad tiene que ser mayor que cero.',
            'lines.*.input_unit_price.required' => 'Falta el costo (0 si no se conoce).',
            'lines.*.input_unit_price.prohibited' => 'Un consumo sale al costo promedio: no lleva precio.',
        ];
    }
}
