<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceOrders;

use App\Application\DTOs\ServiceOrders\RetireServiceBullDTO;
use App\Core\Enums\BullReplacementReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RetireServiceBullRequest extends FormRequest
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
            'male_caravan_id'      => 'required|integer|exists:caravans,id',
            'retirement_date'      => 'required|date',
            'reason'               => ['required', 'string', Rule::enum(BullReplacementReason::class)],
            'destination_batch_id' => 'nullable|integer|exists:batches,id',
            'notes'                => 'nullable|string|max:1000',
        ];
    }

    public function toDTO(int $serviceOrderId, int $companyId, int $userId): RetireServiceBullDTO
    {
        $validated = $this->validated();

        return new RetireServiceBullDTO(
            serviceOrderId: $serviceOrderId,
            companyId: $companyId,
            userId: $userId,
            maleCaravanId: (int) $validated['male_caravan_id'],
            retirementDate: (string) $validated['retirement_date'],
            reason: BullReplacementReason::from($validated['reason']),
            destinationBatchId: isset($validated['destination_batch_id']) ? (int) $validated['destination_batch_id'] : null,
            notes: $validated['notes'] ?? null
        );
    }
}
