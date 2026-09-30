<?php

declare(strict_types=1);

namespace App\Http\Requests\WorkTemplates;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. Domain problems (unknown tags, calves already weaned, missing lineage…) are
 * reported per row by ProcessDest01SubmissionUseCase, so the operator can repair them all at once.
 */
class ProcessDest01Request extends FormRequest
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
            // One weaning batch for every calf (the shape every sheet had before orders)…
            'target_batch_id'       => 'nullable|integer|prohibits:new_batch_name',
            'new_batch_name'        => 'nullable|string|max:255',
            'new_batch_is_confined' => 'nullable|boolean',
            // …or the destinations the operator resolved, one per batch named on the paper.
            'destination_mode'                  => 'nullable|string|in:single,per_animal',
            'destinations'                      => 'nullable|array',
            'destinations.*.key'                => 'required|string|max:255',
            'destinations.*.target_batch_id'    => 'nullable|integer',
            'destinations.*.new_batch'          => 'nullable|array',
            'destinations.*.new_batch.name'     => 'required_with:destinations.*.new_batch|string|max:255',
            'destinations.*.new_batch.is_confined' => 'nullable|boolean',
            'sistema_manejo'        => 'nullable|string|max:50',
            // The weaning order the sheet fulfils, as resolved from its code, and the code as read.
            'weaning_order_id'      => 'nullable|integer',
            'orden_destete'         => 'nullable|string|max:64',
            'fecha_destete'         => 'required|date',
            'tipo_destete'          => 'nullable|string|max:50',
            'lote_origen'           => 'nullable|string|max:255',
            'responsable'           => 'nullable|string|max:255',
            'observaciones'         => 'nullable|string',
            'rows'                  => 'required|array|min:1',
            'rows.*.caravana'       => 'nullable|string|max:100',
            'rows.*.caravana_madre' => 'nullable|string|max:100',
            'rows.*.peso'           => 'nullable|numeric',
            'rows.*.observations'   => 'nullable|string',
            'rows.*.destination_key' => 'nullable|string|max:255',
            'rows.*.manejo'         => 'nullable|string|max:20',
            'rows.*.cs_nueva'       => 'nullable|string|max:120',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_batch_id.prohibits'        => 'Indique un lote de destete existente o uno nuevo, no los dos.',
            'fecha_destete.required'           => 'Falta la fecha de destete.',
            'fecha_destete.date'               => 'La fecha de destete no es válida.',
            'rows.required'                    => 'La planilla no tiene crías cargadas.',
            'rows.min'                         => 'La planilla no tiene crías cargadas.',
            'rows.*.peso.numeric'              => 'El peso de la fila :position no es un número.',
        ];
    }
}
