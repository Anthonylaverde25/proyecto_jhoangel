<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

use App\Http\Requests\Concerns\ScopesToActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape of a provider document. The business rules (type, number, taxes per letter, batches) are the
 * use case's, which answers every broken one at once by field.
 */
class StoreFiscalDocumentRequest extends FormRequest
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
            'provider_id' => ['required', 'integer', Rule::exists('providers', 'id')->where('company_id', $this->companyId())],
            'fiscal_document_type_id' => ['required', 'integer', 'exists:fiscal_document_types,id'],
            'point_of_sale' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'number' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'reference' => ['nullable', 'string', 'max:64'],
            'issued_at' => ['required', 'date_format:Y-m-d'],
            'declared_total' => ['nullable', 'decimal:0,4', 'min:0'],
            'related_fiscal_document_id' => ['nullable', 'integer'],
            'is_valued' => ['nullable', 'boolean'],
            'delivery_note_ids' => ['sometimes', 'array', 'min:1', 'max:20'],
            'delivery_note_ids.*' => ['integer', 'distinct'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:64'],

            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.input_quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'lines.*.input_unit_id' => ['nullable', 'integer'],
            'lines.*.input_presentation_id' => ['nullable', 'integer'],
            // Null only on an unvalued delivery note; the builder says when it is missing (RM4).
            'lines.*.unit_price' => ['nullable', 'decimal:0,6', 'min:0'],
            'lines.*.delivery_note_line_id' => ['nullable', 'integer'],
            'lines.*.batch_id' => ['nullable', 'integer'],
            'lines.*.heads_treated' => ['nullable', 'integer', 'min:1'],
            'lines.*.source_description' => ['nullable', 'string', 'max:191'],
            'lines.*.taxes' => ['sometimes', 'array', 'max:5'],
            'lines.*.taxes.*.tax_id' => ['required', 'integer'],
            'lines.*.taxes.*.tax_rate_id' => ['nullable', 'integer'],
            'lines.*.taxes.*.rate' => ['nullable', 'decimal:0,4', 'min:0', 'max:100'],
            'lines.*.taxes.*.taxable_base' => ['nullable', 'decimal:0,4', 'min:0'],
            'lines.*.taxes.*.amount' => ['nullable', 'decimal:0,4', 'min:0'],

            'document_taxes' => ['sometimes', 'array', 'max:10'],
            'document_taxes.*.tax_id' => ['required', 'integer'],
            'document_taxes.*.rate' => ['nullable', 'decimal:0,4', 'min:0', 'max:100'],
            'document_taxes.*.taxable_base' => ['nullable', 'decimal:0,4', 'min:0'],
            'document_taxes.*.amount' => ['nullable', 'decimal:0,4', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'provider_id.exists' => 'El proveedor no existe en esta empresa.',
            'lines.required' => 'El comprobante no tiene renglones.',
            'lines.*.input_quantity.gt' => 'La cantidad tiene que ser mayor que cero.',
            'lines.*.input_quantity.decimal' => 'La cantidad admite hasta 4 decimales.',
            'lines.*.unit_price.required' => 'Falta el precio.',
            'lines.*.unit_price.decimal' => 'El precio admite hasta 6 decimales.',
            'delivery_note_ids.min' => 'Elegí al menos un remito.',
            'delivery_note_ids.*.distinct' => 'El remito está repetido.',
            'issued_at.date_format' => 'La fecha tiene que tener el formato AAAA-MM-DD.',
        ];
    }
}
