<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use Illuminate\Foundation\Http\FormRequest;

class BulkGestationDiagnosisRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'diagnoses'                       => 'required|array|min:1',
            'diagnoses.*.caravan_id'          => 'required|integer|exists:caravans,id',
            'diagnoses.*.service_order_id'    => 'required|integer|exists:service_orders,id',
            'diagnoses.*.is_pregnant'         => 'required|boolean',
            'diagnoses.*.gestation_stage'     => 'nullable|string|in:head,body,tail',
            'diagnoses.*.gestation_months'    => 'nullable|numeric|min:0|max:9.5',
            'diagnoses.*.confirmed_sire_id'   => 'nullable|integer|exists:caravans,id',
            'diagnoses.*.diagnosis_date'      => 'required|date_format:Y-m-d',
        ];
    }
}
