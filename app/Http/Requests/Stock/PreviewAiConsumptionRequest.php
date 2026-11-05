<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

use App\Application\DTOs\Stock\AiConsumptionDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The consumption an AI client wants to show the user before recording it. The rules of the business
 * answer in the body; only a malformed request is a 422.
 */
class PreviewAiConsumptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer'],
            'batch_id' => ['required', 'integer'],
            'input_quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'input_unit_id' => ['nullable', 'integer'],
            'input_presentation_id' => ['nullable', 'integer'],
            'occurred_at' => ['required', 'date_format:Y-m-d'],
            'warehouse_id' => ['nullable', 'integer'],
            'heads_treated' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function toDto(): AiConsumptionDTO
    {
        return AiConsumptionDTO::fromArray($this->validated(), $this->user()?->id);
    }
}
