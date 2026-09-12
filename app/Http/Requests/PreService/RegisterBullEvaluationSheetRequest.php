<?php

declare(strict_types=1);

namespace App\Http\Requests\PreService;

use App\Core\Enums\SampleDestinationPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterBullEvaluationSheetRequest extends FormRequest
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
            // ADR-3: the acting professional comes from the veterinarians catalogue, never from
            // the users table. Who operated the keyboard is taken from the session instead.
            'veterinarian_id' => ['required', 'integer', 'exists:veterinarians,id'],


            // ADR-12: no protocol_number. The act number is minted by the system and the
            // laboratory report number does not exist yet.
            // ADR-39: the centre the professional was working with. Optional — a field sampling
            // may have none — and described here, never picked from a catalogue (ADR-29).
            'institution' => ['nullable', 'array'],
            'institution.nombre' => ['required_with:institution', 'string', 'max:150'],
            'institution.cuit' => ['nullable', 'string', 'max:13'],
            'institution.direccion' => ['nullable', 'string', 'max:300'],
            'institution.codigo_oficial' => ['nullable', 'string', 'max:50'],
            'institution.contacto' => ['nullable', 'string', 'max:150'],
            // ADR-40: an intention. Nothing downstream validates against it.
            'destination_plan' => ['nullable', Rule::enum(SampleDestinationPlan::class)],
            'dispatch_note_number' => ['nullable', 'string', 'max:50'],
            'dispatched_at' => ['nullable', 'date'],

            'evaluation_date' => ['required', 'date'],
            'sample_round' => ['required', 'integer', 'min:1', 'max:10'],
            'observations' => ['nullable', 'string', 'max:2000'],

            'bulls' => ['required', 'array', 'min:1'],
            'bulls.*.caravan_id' => ['required', 'integer', 'exists:caravans,id'],
            'bulls.*.scrotal_circumference_cm' => ['nullable', 'numeric', 'min:15', 'max:60'],
            'bulls.*.body_condition_score' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'bulls.*.libido' => ['nullable', Rule::in(['BAJA', 'MEDIA', 'ALTA', 'MUY_ALTA'])],
            'bulls.*.aplomo_notes' => ['nullable', 'string', 'max:1000'],
            'bulls.*.observations' => ['nullable', 'string', 'max:1000'],

            // ADR-26: one act may span two chute days. Omitted, the row inherits the act's date.
            'bulls.*.extracted_on' => ['nullable', 'date'],

            // A drawn tube with no label breaks the chain of custody: the sample can no longer be
            // matched to the animal when the laboratory reports back.
            'bulls.*.prepuce_scrape' => ['nullable', 'boolean'],
            'bulls.*.prepuce_scrape_tube' => ['nullable', 'required_if:bulls.*.prepuce_scrape,true', 'string', 'max:50'],
            'bulls.*.blood_serology' => ['nullable', 'boolean'],
            'bulls.*.blood_serology_tube' => ['nullable', 'required_if:bulls.*.blood_serology,true', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'veterinarian_id.required' => 'Indique el profesional actuante: el acta de manga se emite a su nombre.',
            'bulls.*.prepuce_scrape_tube.required_if' => 'Todo raspaje prepucial debe registrar el número de tubo.',
            'bulls.*.blood_serology_tube.required_if' => 'Toda serología debe registrar el número de tubo.',
        ];
    }
}
