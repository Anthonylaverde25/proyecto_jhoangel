<?php

declare(strict_types=1);

namespace App\Http\Requests\WorkTemplates;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. Domain problems (unknown females, calf tags in use, dates outside the gestation…) are
 * reported per row by ProcessPar01SubmissionUseCase, so the operator can repair them all at once.
 */
class ProcessPar01Request extends FormRequest
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
            // The birth order the sheet fulfils, as resolved from its code, and the code as read.
            'birth_order_id' => 'nullable|integer',
            'orden_paricion' => 'nullable|string|max:64',
            'fecha_recorrida' => 'nullable|string|max:20',
            'lote' => 'nullable|string|max:255',
            'responsable' => 'nullable|string|max:255',
            'observaciones' => 'nullable|string',
            'rows' => 'required|array|min:1',
            'rows.*.caravana_madre' => 'nullable|string|max:100',
            'rows.*.resultado' => 'nullable|string|max:40',
            'rows.*.caravana_cria' => 'nullable|string|max:100',
            'rows.*.sexo' => 'nullable|string|max:20',
            'rows.*.peso' => 'nullable|numeric',
            'rows.*.raza' => 'nullable|string|max:120',
            'rows.*.breed_id' => 'nullable|integer',
            'rows.*.pelaje' => 'nullable|string|max:60',
            'rows.*.color_id' => 'nullable|integer',
            // Not on the paper: chosen in the review, or 0 / empty by default.
            'rows.*.dientes' => 'nullable|integer',
            'rows.*.father_id' => 'nullable|integer',
            'rows.*.fecha_nacimiento' => 'nullable|string|max:20',
            'rows.*.observations' => 'nullable|string',
            // The "Fuera de orden" box: a calving the order did not list, declared on the paper.
            'rows.*.fuera_de_orden' => 'nullable',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.required' => 'La planilla no tiene vientres cargados.',
            'rows.min' => 'La planilla no tiene vientres cargados.',
            'rows.*.peso.numeric' => 'El peso de la fila :position no es un número.',
        ];
    }
}
