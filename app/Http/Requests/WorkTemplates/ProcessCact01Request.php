<?php

declare(strict_types=1);

namespace App\Http\Requests\WorkTemplates;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. Whether a batch exists, whether an animal is in the source batch, and
 * whether the dentition reads as a real one are domain questions, answered by
 * ProcessCact01SubmissionUseCase so they can be reported all at once per row.
 *
 * `rows.*.caravana` is nullable on purpose: a blank printed line is not a validation
 * error, and a missing tag on a written line comes back as a row error, next to the
 * others of that same row, instead of as a field error with no context.
 */
final class ProcessCact01Request extends FormRequest
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
            'source_batch_id' => 'required|integer',
            'fecha_movimiento' => 'required|date',
            'actividad_origen' => 'nullable|string|max:255',
            'actividad_destino' => 'nullable|string|max:255',
            'sistema_manejo' => 'nullable|string|max:50',
            'total_cabezas' => 'nullable|numeric',
            'peso_total' => 'nullable|numeric',
            'responsable' => 'nullable|string|max:255',
            'observaciones' => 'nullable|string|max:2000',

            'destinations' => 'required|array|min:1',
            'destinations.*.key' => 'required|string|max:255',
            'destinations.*.target_batch_id' => 'nullable|integer',
            'destinations.*.new_batch' => 'nullable|array',
            'destinations.*.new_batch.name' => 'required_with:destinations.*.new_batch|string|max:255',
            'destinations.*.new_batch.activity_id' => 'required_with:destinations.*.new_batch|integer',
            'destinations.*.new_batch.batch_type_id' => 'required_with:destinations.*.new_batch|integer',
            'destinations.*.new_batch.is_confined' => 'nullable|boolean',

            'rows' => 'required|array|min:1',
            'rows.*.caravana' => 'nullable|string|max:255',
            'rows.*.peso_actual' => 'nullable|numeric',
            'rows.*.sexo' => 'nullable|string|max:20',
            'rows.*.categoria' => 'nullable|string|max:255',
            'rows.*.dientes' => 'nullable|string|max:50',
            'rows.*.destination_key' => 'nullable|string|max:255',
            'rows.*.observations' => 'nullable|string|max:1000',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('destinations', []) as $index => $destination) {
                $hasExisting = ($destination['target_batch_id'] ?? null) !== null;
                $hasNew = is_array($destination['new_batch'] ?? null);

                if ($hasExisting && $hasNew) {
                    $validator->errors()->add(
                        "destinations.{$index}",
                        'Un destino es un lote existente o uno nuevo, nunca los dos.'
                    );
                }

                if (!$hasExisting && !$hasNew) {
                    $validator->errors()->add(
                        "destinations.{$index}",
                        'Cada destino tiene que resolverse a un lote existente o a uno nuevo.'
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source_batch_id.required' => 'Falta indicar el lote de origen.',
            'fecha_movimiento.required' => 'Falta la fecha del movimiento.',
            'fecha_movimiento.date' => 'La fecha del movimiento no es una fecha válida.',
            'destinations.required' => 'La planilla no tiene ningún lote de destino resuelto.',
            'destinations.min' => 'La planilla no tiene ningún lote de destino resuelto.',
            'rows.required' => 'La planilla no tiene filas.',
            'rows.min' => 'La planilla no tiene filas.',
        ];
    }
}
