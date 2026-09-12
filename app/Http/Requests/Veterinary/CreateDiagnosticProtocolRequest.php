<?php

declare(strict_types=1);

namespace App\Http\Requests\Veterinary;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Use Case 2 ingestion. The payload arrives as multipart because it carries the original
 * evidence, so `samples` travels as a JSON encoded string and is decoded before validation.
 */
class CreateDiagnosticProtocolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('samples'))) {
            $decoded = json_decode((string) $this->input('samples'), true);

            if (is_array($decoded)) {
                $this->merge(['samples' => $decoded]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxSizeKb = (int) config('livestock.attachments.max_size_kb', 10240);
        $maxFiles = (int) config('livestock.attachments.max_per_protocol', 10);
        /** @var list<string> $allowedMimes */
        $allowedMimes = (array) config('livestock.attachments.allowed_mimes', ['jpg', 'jpeg', 'png', 'pdf']);

        return [
            'protocol_number' => ['required', 'string', 'max:100'],
            'veterinarian_id' => ['nullable', 'integer', 'exists:veterinarians,id'],
            'sample_date' => ['required', 'date'],
            'result_date' => ['required', 'date', 'after_or_equal:sample_date'],
            'source_channel' => ['required', Rule::in(['PORTAL_VET', 'OWNER_DIGITIZED'])],
            'observations' => ['nullable', 'string', 'max:2000'],

            'samples' => ['required', 'array', 'min:1'],
            'samples.*.caravan_id' => ['required', 'integer', 'exists:caravans,id'],
            'samples.*.pathogen_id' => ['required', 'integer', 'exists:pathogens,id'],
            'samples.*.sample_type' => ['required', Rule::in(['PREPUCE_SCRAPE', 'BLOOD_SEROLOGY', 'SEMEN_CULTURE', 'TUBERCULIN_TEST'])],
            'samples.*.sample_round' => ['required', 'integer', 'min:1', 'max:10'],
            'samples.*.status' => ['required', Rule::in(['PENDING_RESULTS', 'NEGATIVE_CLEARED', 'POSITIVE_DETECTED'])],
            'samples.*.tube_number' => ['nullable', 'string', 'max:50'],
            'samples.*.notes' => ['nullable', 'string', 'max:500'],

            'attachments' => ['nullable', 'array', 'max:' . $maxFiles],
            'attachments.*' => ['file', 'mimes:' . implode(',', $allowedMimes), 'max:' . $maxSizeKb],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'samples.required' => 'El protocolo debe contener al menos una determinación de laboratorio.',
            'result_date.after_or_equal' => 'La fecha de resultado no puede ser anterior a la toma de muestra.',
            'attachments.*.mimes' => 'Solo se admiten imágenes (JPG, PNG, WEBP, HEIC) o PDF como evidencia.',
        ];
    }
}
