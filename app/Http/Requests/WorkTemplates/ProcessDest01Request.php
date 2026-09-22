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
            'target_batch_id'       => 'nullable|integer|required_without:new_batch_name|prohibits:new_batch_name',
            'new_batch_name'        => 'nullable|string|max:255|required_without:target_batch_id',
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_batch_id.required_without' => 'Falta indicar el lote de destete: uno existente o el nombre de uno nuevo.',
            'new_batch_name.required_without'  => 'Falta indicar el lote de destete: uno existente o el nombre de uno nuevo.',
            'target_batch_id.prohibits'        => 'Indique un lote de destete existente o uno nuevo, no los dos.',
            'fecha_destete.required'           => 'Falta la fecha de destete.',
            'fecha_destete.date'               => 'La fecha de destete no es válida.',
            'rows.required'                    => 'La planilla no tiene crías cargadas.',
            'rows.min'                         => 'La planilla no tiene crías cargadas.',
            'rows.*.peso.numeric'              => 'El peso de la fila :position no es un número.',
        ];
    }
}
