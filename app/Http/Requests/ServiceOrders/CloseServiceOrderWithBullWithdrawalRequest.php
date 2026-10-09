<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceOrders;

use App\Application\DTOs\ServiceOrders\CloseServiceOrderDTO;
use Illuminate\Foundation\Http\FormRequest;

class CloseServiceOrderWithBullWithdrawalRequest extends FormRequest
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
            'withdrawal_date'                          => 'required|date',
            'observations'                             => 'nullable|string|max:1000',
            'default_destination_batch_id'             => 'nullable|integer|exists:batches,id',
            'bull_destinations'                        => 'nullable|array',
            'bull_destinations.*.male_caravan_id'      => 'required_with:bull_destinations|integer|exists:caravans,id',
            'bull_destinations.*.destination_batch_id' => 'required_with:bull_destinations|integer|exists:batches,id',
        ];
    }

    public function toDTO(int $serviceOrderId, int $companyId, int $userId): CloseServiceOrderDTO
    {
        $validated = $this->validated();

        $destinations = [];
        if (!empty($validated['bull_destinations']) && is_array($validated['bull_destinations'])) {
            foreach ($validated['bull_destinations'] as $dest) {
                if (isset($dest['male_caravan_id'], $dest['destination_batch_id'])) {
                    $destinations[(int) $dest['male_caravan_id']] = (int) $dest['destination_batch_id'];
                }
            }
        }

        return new CloseServiceOrderDTO(
            serviceOrderId: $serviceOrderId,
            companyId: $companyId,
            userId: $userId,
            withdrawalDate: (string) $validated['withdrawal_date'],
            defaultDestinationBatchId: isset($validated['default_destination_batch_id']) ? (int) $validated['default_destination_batch_id'] : null,
            bullDestinations: $destinations,
            observations: $validated['observations'] ?? null
        );
    }
}
