<?php

declare(strict_types=1);

namespace App\Http\Requests\WeaningDeviations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Revisar": the deviations seen and the note that justifies them, always written.
 */
final class ReviewWeaningDeviationsRequest extends FormRequest
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
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct',
            'note' => 'required|string|max:2000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Elegí al menos un desvío.',
            'note.required' => 'Escribí la justificación de la revisión.',
        ];
    }
}
