<?php

declare(strict_types=1);

namespace App\Http\Requests\Veterinary;

use App\Core\Enums\LabSampleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterLabReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Multipart when the laboratory's PDF travels with the transcription (ADR-27), so the
     * nested `lines` array arrives as a JSON string and is decoded before validation.
     */
    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        // Both institution blocks travel as JSON strings under multipart.
        foreach (['reporting_institution', 'analysing_institution'] as $key) {
            $institution = $this->input($key);

            if (!is_string($institution)) {
                continue;
            }

            $decodedInstitution = json_decode($institution, true);

            if (is_array($decodedInstitution)) {
                $this->merge([$key => $decodedInstitution]);
            }
        }

        // "0"/"false" arrive as strings under multipart and would both read as true.
        if ($this->has('is_derived')) {
            $this->merge([
                'is_derived' => filter_var($this->input('is_derived'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        if (is_string($lines)) {
            $decoded = json_decode($lines, true);

            if (is_array($decoded)) {
                $this->merge(['lines' => $decoded]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ADR-12: this one IS typed by hand — it is the number printed on the physical report.
            'lab_report_number' => ['required', 'string', 'max:100'],
            'result_date' => ['required', 'date'],
            // ADR-29: the centre the professional reports from, described here and nowhere else.
            'reporting_institution' => ['required', 'array'],
            'reporting_institution.nombre' => ['required', 'string', 'max:150'],
            'reporting_institution.cuit' => ['nullable', 'string', 'max:13'],
            'reporting_institution.direccion' => ['nullable', 'string', 'max:300'],
            'reporting_institution.codigo_oficial' => ['nullable', 'string', 'max:50'],
            'reporting_institution.contacto' => ['nullable', 'string', 'max:150'],

            // ADR-31 (rev.): declared by the professional, and it is what opens the second block.
            'is_derived' => ['sometimes', 'boolean'],
            'analysing_institution' => ['nullable', 'array', 'required_if:is_derived,true'],
            'analysing_institution.nombre' => ['required_with:analysing_institution', 'string', 'max:150'],
            'analysing_institution.cuit' => ['nullable', 'string', 'max:13'],
            'analysing_institution.direccion' => ['nullable', 'string', 'max:300'],
            'analysing_institution.codigo_oficial' => ['nullable', 'string', 'max:50'],
            'analysing_institution.contacto' => ['nullable', 'string', 'max:150'],
            'observations' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sample_id' => ['required', 'integer', 'exists:bull_lab_samples,id'],
            'lines.*.status' => [
                'required',
                Rule::in([
                    LabSampleStatus::NEGATIVE_CLEARED->value,
                    LabSampleStatus::POSITIVE_DETECTED->value,
                    LabSampleStatus::PENDING_RESULTS->value,
                ]),
            ],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],

            // ADR-35 (rev.): mandatory on a derivation, which the use case enforces — the rule
            // here only bounds what may be uploaded.
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lab_report_number.required' => 'Ingrese el número de protocolo impreso en el informe del laboratorio.',
            'lines.required' => 'El informe debe resolver al menos una muestra del acta.',
            'reporting_institution.required' => 'Indique la institución desde la que informa: el protocolo siempre lleva su nombre y CUIT.',
            'reporting_institution.nombre.required' => 'Indique el nombre de la institución desde la que informa.',
            'analysing_institution.required_if' => 'Marcó que el análisis fue derivado: indique quién lo procesó.',
        ];
    }
}
