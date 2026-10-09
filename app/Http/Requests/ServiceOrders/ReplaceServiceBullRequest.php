<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceOrders;

use App\Application\DTOs\ServiceOrders\ReplaceServiceBullDTO;
use App\Core\Enums\BullReplacementReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceServiceBullRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'retired_male_caravan_id'     => 'required|integer|exists:caravans,id',
            'replacement_male_caravan_id' => 'required|integer|different:retired_male_caravan_id|exists:caravans,id',
            'replacement_date'            => 'required|date',
            'reason'                      => ['required', 'string', Rule::enum(BullReplacementReason::class)],
            'destination_batch_id'        => 'nullable|integer|exists:batches,id',
            'notes'                       => 'nullable|string|max:1000',
        ];
    }

    public function toDTO(int $serviceOrderId, int $companyId, int $userId): ReplaceServiceBullDTO
    {
        $validated = $this->validated();

        return new ReplaceServiceBullDTO(
            serviceOrderId: $serviceOrderId,
            companyId: $companyId,
            userId: $userId,
            retiredMaleCaravanId: (int) $validated['retired_male_caravan_id'],
            replacementMaleCaravanId: (int) $validated['replacement_male_caravan_id'],
            replacementDate: (string) $validated['replacement_date'],
            reason: BullReplacementReason::from($validated['reason']),
            destinationBatchId: isset($validated['destination_batch_id']) ? (int) $validated['destination_batch_id'] : null,
            notes: $validated['notes'] ?? null
        );
    }
}
