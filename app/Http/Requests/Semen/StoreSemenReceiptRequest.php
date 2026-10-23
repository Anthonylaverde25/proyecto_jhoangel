<?php

declare(strict_types=1);

namespace App\Http\Requests\Semen;

use Illuminate\Foundation\Http\FormRequest;

/** Shapes only; the entry's rules are domain rules answered together, by cell (SE1–SE5). */
class StoreSemenReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'occurred_at' => ['required', 'date'],
            'warehouse_id' => ['nullable', 'integer'],
            'provider_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'lines' => ['present', 'array', 'max:100'],
            'lines.*.semen_sire_id' => ['nullable', 'integer'],
            'lines.*.semen_type' => ['nullable', 'string', 'max:16'],
            'lines.*.lot_code' => ['nullable', 'string', 'max:60'],
            'lines.*.collection_date' => ['nullable', 'date'],
            'lines.*.quantity' => ['nullable'],
            'lines.*.unit_cost' => ['nullable'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'occurred_at.required' => 'Indicá la fecha.',
            'lines.max' => 'Un ingreso tiene hasta 100 renglones.',
        ];
    }
}
