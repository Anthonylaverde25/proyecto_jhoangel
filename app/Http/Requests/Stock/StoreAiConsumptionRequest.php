<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

use App\Application\DTOs\Stock\AiConsumptionDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The option of a preview the user confirmed. The origin and the idempotency key are not asked for:
 * the route sets the first and the confirmation is the second.
 */
class StoreAiConsumptionRequest extends FormRequest
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
            'warehouse_id' => ['required', 'integer'],
            'input_quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'input_unit_id' => ['nullable', 'integer'],
            'input_presentation_id' => ['nullable', 'integer'],
            'heads_treated' => ['required', 'integer', 'min:1'],
            'occurred_at' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'transfers' => ['nullable', 'array', 'max:20'],
            'transfers.*.from_warehouse_id' => ['required', 'integer'],
            'transfers.*.quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'confirmation_token' => ['required', 'string', 'max:64'],
            'acknowledge_warnings' => ['nullable', 'boolean'],
        ];
    }

    public function toDto(): AiConsumptionDTO
    {
        return AiConsumptionDTO::fromArray($this->validated(), $this->user()?->id);
    }
}
