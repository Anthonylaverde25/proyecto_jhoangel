<?php

declare(strict_types=1);

namespace App\Http\Requests\Veterinary;

use App\Core\Enums\SampleDestinationPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-13: the signature carries no identity fields on purpose. Who signs is taken from the
 * resolved portal session, never from the payload.
 */
class SignExtractionActRequest extends FormRequest
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
            'observations' => ['nullable', 'string', 'max:2000'],
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
        ];
    }
}
