<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceOrders;

use App\Application\DTOs\ServiceOrders\MarkFemalesNotDiagnosedDTO;
use App\Core\Enums\NotDiagnosedReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarkNotDiagnosedRequest extends FormRequest
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
            'round_date'                        => 'required|date_format:Y-m-d|before_or_equal:today',
            'females'                           => 'required|array|min:1',
            'females.*.female_caravan_id'       => 'required|integer|exists:caravans,id',
            'females.*.reason'                  => ['required', Rule::enum(NotDiagnosedReason::class)],
            'females.*.notes'                   => 'nullable|string|max:255',
        ];
    }

    public function toDTO(int $serviceOrderId, int $companyId, ?int $userId): MarkFemalesNotDiagnosedDTO
    {
        $validated = $this->validated();

        return new MarkFemalesNotDiagnosedDTO(
            serviceOrderId: $serviceOrderId,
            companyId: $companyId,
            userId: $userId,
            roundDate: (string) $validated['round_date'],
            females: MarkFemalesNotDiagnosedDTO::rowsFromArray((array) $validated['females'])
        );
    }
}
