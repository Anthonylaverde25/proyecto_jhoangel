<?php

declare(strict_types=1);

namespace App\Http\Requests\Caravans;

use App\Application\DTOs\RecordAiWeighingDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Same weight and date rules as RecordCaravanWeightRequest. Type and source are not fields: the
 * route fixes them. The date is a plain day so that a repeated weighing is recognised.
 */
class RecordAiWeighingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'weight' => 'required|numeric|gt:0|max:2000',
            'weighing_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'notes' => 'nullable|string|max:500',
            'acknowledge_warnings' => 'sometimes|boolean',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'weighing_date.before_or_equal' => 'La fecha del pesaje no puede ser futura.',
        ];
    }

    public function toDto(int $caravanId): RecordAiWeighingDTO
    {
        return new RecordAiWeighingDTO(
            caravanId: $caravanId,
            weight: (float) $this->validated('weight'),
            date: (string) $this->validated('weighing_date'),
            notes: $this->validated('notes'),
            acknowledgeWarnings: $this->boolean('acknowledge_warnings'),
        );
    }
}
