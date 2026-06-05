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
            'rows.*.diagnostico'            => 'required|string|in:PREGNANT,EMPTY,Preñada,Vacía,prenada,vacia',
            'rows.*.gestation_stage'        => 'nullable|string|in:head,body,tail,cabeza,cuerpo,cola',
            'rows.*.observations'           => 'nullable|string|max:500',
            'service_order_id'              => 'required|integer|exists:service_orders,id',
            'diagnosis_date'                => 'nullable|date_format:Y-m-d',
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
            'rows.*.diagnostico.required'          => 'Each row must have a diagnosis value.',
            'rows.*.diagnostico.in'                => 'Diagnosis must be PREGNANT or EMPTY.',
            'rows.*.gestation_stage.in'            => 'Gestation stage must be one of: head, body, tail.',
            'service_order_id.required'            => 'A service order is required for traceability.',
            'service_order_id.exists'              => 'The specified service order does not exist.',
        ];
    }
}
