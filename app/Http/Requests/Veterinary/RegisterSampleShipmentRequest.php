<?php

declare(strict_types=1);

namespace App\Http\Requests\Veterinary;

use Illuminate\Foundation\Http\FormRequest;

class RegisterSampleShipmentRequest extends FormRequest
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
            'shipped_on' => ['required', 'date'],
            'cold_chain_ok' => ['required', 'boolean'],
            // §4: a broken cold chain is a professional judgement, and it goes in writing.
            'condition_notes' => ['nullable', 'string', 'max:2000', 'required_if:cold_chain_ok,false'],

            // ADR-29: the institution is described here. The CUIT's check digit is validated by
            // the value object, not by a regex that would accept any eleven digits.
            'institution' => ['required', 'array'],
            'institution.nombre' => ['required', 'string', 'max:150'],
            'institution.cuit' => ['nullable', 'string', 'max:13'],
            'institution.direccion' => ['nullable', 'string', 'max:300'],
            'institution.codigo_oficial' => ['nullable', 'string', 'max:50'],
            'institution.contacto' => ['nullable', 'string', 'max:150'],

            'sample_ids' => ['required', 'array', 'min:1'],
            'sample_ids.*' => ['required', 'integer', 'exists:bull_lab_samples,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'institution.nombre.required' => 'La institución necesita al menos un nombre: es lo que identifica dónde están las muestras.',
            'sample_ids.required' => 'Elija al menos un tubo: un envío que no declara nada no es un envío.',
            'condition_notes.required_if' => 'Si la cadena de frío se cortó hay que dejar constancia de en qué condiciones viajaron.',
        ];
    }
}
