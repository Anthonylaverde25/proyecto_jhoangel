<?php

declare(strict_types=1);

namespace App\Http\Requests\WorkTemplates;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. Domain problems (unknown tags, unfit bull, pregnant females…) are reported
 * per row by ProcessLser01SubmissionUseCase, so the operator can repair them all at once.
 */
class ProcessLser01Request extends FormRequest
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
            'lote'                => 'required|string|max:255',
            'toro_caravana'       => 'required|string|max:100',
            'planned_start_date'  => 'required|date',
            'planned_end_date'    => 'nullable|date|after_or_equal:planned_start_date',
            'responsable'         => 'nullable|string|max:255',
            'observaciones'       => 'nullable|string',
            'rows'                => 'required|array|min:1',
            'rows.*.caravana'     => 'nullable|string|max:100',
            'rows.*.observations' => 'nullable|string',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lote.required'               => 'Falta el nombre del lote de servicio.',
            'toro_caravana.required'      => 'Falta la caravana del toro.',
            'planned_start_date.required' => 'Falta la fecha de inicio de servicio.',
            'planned_start_date.date'     => 'La fecha de inicio de servicio no es válida.',
            'planned_end_date.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
            'rows.required'               => 'La planilla no tiene vientres cargados.',
            'rows.min'                    => 'La planilla no tiene vientres cargados.',
        ];
    }
}
