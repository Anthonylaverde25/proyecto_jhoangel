<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use Illuminate\Foundation\Http\FormRequest;

class ImportCaravansRequest extends FormRequest
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
            'rows'                        => 'required|array|min:1',
            'rows.*.identification'       => 'required|string',
            'rows.*.category'             => 'nullable|string',
            'rows.*.teeth'                => 'nullable|string',
            'rows.*.entry_weight'         => 'nullable|string',
            'rows.*.exit_weight'          => 'nullable|string',
            'rows.*.breed'                => 'nullable|string',
            'rows.*.sex'                  => 'nullable|string',
            'rows.*.entry_date'           => 'nullable|string',
            'rows.*.is_empty'             => 'nullable',
            'rows.*.gestational_stage'    => 'nullable|string',
            'rows.*.estadioestimado'       => 'nullable|string',
            'rows.*.estadio_estimado'     => 'nullable|string',
            'rows.*.diagnostico'          => 'nullable|string',
            'rows.*.diagnstico'           => 'nullable|string',
            'rows.*.observaciones'        => 'nullable|string',
            'rows.*.observations'         => 'nullable|string',
            'work_type'                   => 'nullable|string|in:entry,update,exit',
            'batch_id'                    => 'nullable|integer|exists:batches,id',
            'farm_id'                     => 'nullable|integer|exists:farms,id',
            'batch_name'                  => 'nullable|string',
            'empty_destination_batch_id'  => 'nullable|integer|exists:batches,id',
            'service_order_id'            => 'nullable|integer|exists:service_orders,id',
        ];
    }
}
