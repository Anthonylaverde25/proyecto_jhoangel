<?php

declare(strict_types=1);

namespace App\Http\Requests\WorkTemplates;

use Illuminate\Foundation\Http\FormRequest;

class ProcessIng01Request extends FormRequest
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
            'batch_name'             => 'nullable|string|max:100',
            'lote'                   => 'nullable|string|max:100',
            'provider_batch_name'    => 'nullable|string|max:100',
            'lote_proveedor'         => 'nullable|string|max:100',
            'lote_origen'            => 'nullable|string|max:100',
            'lt_origen'              => 'nullable|string|max:100',
            'provider_name'          => 'nullable|string|max:150',
            'proveedor'              => 'nullable|string|max:150',
            'provider_farm_name'     => 'nullable|string|max:150',
            'estab_origen'           => 'nullable|string|max:150',
            'establecimiento_origen' => 'nullable|string|max:150',
            'farm_origen'            => 'nullable|string|max:150',
            'entry_date'             => 'nullable|string|max:30',
            'fecha'                  => 'nullable|string|max:30',
            'provider_cuit'          => 'nullable|string|max:25',
            'cuit'                   => 'nullable|string|max:25',
            'provider_renspa'        => 'nullable|string|max:50',
            'renspa'                 => 'nullable|string|max:50',
            'guia_dte'               => 'nullable|string|max:50',
            'dte'                    => 'nullable|string|max:50',
            'activity'               => 'nullable|string|max:100',
            'actividad'              => 'nullable|string|max:100',
            'activity_code'          => 'nullable|string|max:50',
            'activity_id'            => 'nullable|integer',
            'caravans'               => 'nullable|array',
            'rows'                   => 'nullable|array',
            'caravans.*.caravana'       => 'required_without:caravans.*.identification|string|max:30',
            'caravans.*.identification' => 'required_without:caravans.*.caravana|string|max:30',
            'caravans.*.category'       => 'nullable|string|max:50',
            'caravans.*.sex'            => 'nullable|string|max:10',
            'caravans.*.breed'          => 'nullable|string|max:100',
            'caravans.*.teeth'          => 'nullable',
            'caravans.*.entry_weight'   => 'nullable',
            'caravans.*.observations'   => 'nullable|string|max:500',
        ];
    }
}
