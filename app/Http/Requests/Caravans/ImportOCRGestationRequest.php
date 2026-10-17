<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use Illuminate\Foundation\Http\FormRequest;

class ImportOCRGestationRequest extends FormRequest
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
            'rows'                          => 'required|array|min:1',
            'rows.*.identification'         => 'required|string|max:50',
            // Read row by row by the use case: a blank cell leaves the female pending and an
            // unreadable one is reported on its row, without rejecting the whole sheet.
            'rows.*.diagnostico'            => 'nullable|string|max:20',
            'rows.*.gestation_stage'        => 'nullable|string|max:20',
            'rows.*.gestation_months'       => 'nullable|numeric|min:0|max:9.5',
            'rows.*.observations'           => 'nullable|string|max:500',
            'service_order_id'              => 'required|integer|exists:service_orders,id',
            'diagnosis_date'                => 'nullable|date_format:Y-m-d',
            'empty_cows_batch_id'           => 'nullable|integer|exists:batches,id',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.required'                       => 'At least one row is required.',
            'rows.*.identification.required'       => 'Each row must have a caravan identification.',
            'service_order_id.required'            => 'A service order is required for traceability.',
            'service_order_id.exists'              => 'The specified service order does not exist.',
        ];
    }
}
