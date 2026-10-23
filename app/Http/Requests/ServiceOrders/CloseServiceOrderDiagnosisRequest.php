<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceOrders;

use App\Application\DTOs\RegisterGestationDiagnosisDTO;
use App\Application\DTOs\ServiceOrders\CloseServiceOrderDiagnosisDTO;
use App\Application\DTOs\ServiceOrders\MarkFemalesNotDiagnosedDTO;
use App\Core\Enums\NotDiagnosedReason;
use App\Core\Enums\NotInseminatedReason;
use App\Core\Enums\ServiceClosureBullAction;
use App\Core\Enums\ServiceClosureRemainingFemalesAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CloseServiceOrderDiagnosisRequest extends FormRequest
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
            'closing_date'                          => 'required|date_format:Y-m-d',
            'round_date'                            => 'nullable|date_format:Y-m-d',
            'observations'                          => 'nullable|string|max:1000',

            'diagnoses'                             => 'nullable|array',
            'diagnoses.*.caravan_id'                => 'required|integer|exists:caravans,id',
            'diagnoses.*.is_pregnant'               => 'required|boolean',
            'diagnoses.*.gestation_stage'           => 'nullable|string|in:head,body,tail',
            'diagnoses.*.gestation_months'          => 'nullable|numeric|min:0|max:9.5',
            'diagnoses.*.confirmed_sire_id'         => 'nullable|integer|exists:caravans,id',
            'diagnoses.*.empty_destination_batch_id' => 'nullable|integer|exists:batches,id',

            'not_diagnosed'                         => 'nullable|array',
            'not_diagnosed.*.female_caravan_id'     => 'required|integer|exists:caravans,id',
            'not_diagnosed.*.reason'                => ['required', Rule::enum(NotDiagnosedReason::class)],
            'not_diagnosed.*.notes'                 => 'nullable|string|max:255',

            'empties_target_batch_id'               => 'nullable|integer|exists:batches,id',

            'bull_action'                           => ['nullable', Rule::enum(ServiceClosureBullAction::class)],
            'bull_destination_batch_id'             => 'nullable|integer|exists:batches,id',
            'bull_destinations'                     => 'nullable|array',
            'bull_destinations.*.male_caravan_id'   => 'required_with:bull_destinations|integer|exists:caravans,id',
            'bull_destinations.*.destination_batch_id' => 'required_with:bull_destinations|integer|exists:batches,id',
            'scheduled_movement_date'               => 'nullable|date_format:Y-m-d',

            'remaining_females_action'              => ['nullable', Rule::enum(ServiceClosureRemainingFemalesAction::class)],
            'remaining_females_target_batch_id'     => 'nullable|integer|exists:batches,id',

            // AI (semen S-B): optional note on the females never inseminated (IA12).
            'not_inseminated_reasons'               => 'nullable|array',
            'not_inseminated_reasons.*.female_caravan_id' => 'required|integer',
            'not_inseminated_reasons.*.reason'      => ['nullable', Rule::enum(NotInseminatedReason::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $diagnosed = array_map(fn ($row) => (int) ($row['caravan_id'] ?? 0), (array) $this->input('diagnoses', []));
            $declared = array_map(fn ($row) => (int) ($row['female_caravan_id'] ?? 0), (array) $this->input('not_diagnosed', []));

            if (array_intersect($diagnosed, $declared) !== []) {
                $validator->errors()->add('not_diagnosed', 'Un vientre no puede estar diagnosticado y declarado "sin diagnóstico" a la vez.');
            }

            if (count($diagnosed) !== count(array_unique($diagnosed))) {
                $validator->errors()->add('diagnoses', 'Un vientre aparece dos veces en los diagnósticos.');
            }
        });
    }

    public function toDTO(int $serviceOrderId, int $companyId, ?int $userId): CloseServiceOrderDiagnosisDTO
    {
        $validated = $this->validated();
        $roundDate = (string) ($validated['round_date'] ?? $validated['closing_date']);

        $diagnoses = array_map(
            static fn (array $row) => RegisterGestationDiagnosisDTO::fromArray($row + [
                'service_order_id' => $serviceOrderId,
                'diagnosis_date' => $roundDate,
                'user_id' => $userId,
            ]),
            (array) ($validated['diagnoses'] ?? [])
        );

        $bullDestinations = [];
        foreach ((array) ($validated['bull_destinations'] ?? []) as $destination) {
            $bullDestinations[(int) $destination['male_caravan_id']] = (int) $destination['destination_batch_id'];
        }

        return new CloseServiceOrderDiagnosisDTO(
            serviceOrderId: $serviceOrderId,
            companyId: $companyId,
            userId: $userId,
            closingDate: (string) $validated['closing_date'],
            roundDate: $roundDate,
            diagnoses: array_values($diagnoses),
            notDiagnosed: MarkFemalesNotDiagnosedDTO::rowsFromArray((array) ($validated['not_diagnosed'] ?? [])),
            emptiesTargetBatchId: isset($validated['empties_target_batch_id']) ? (int) $validated['empties_target_batch_id'] : null,
            bullAction: isset($validated['bull_action']) ? ServiceClosureBullAction::from((string) $validated['bull_action']) : null,
            bullDestinations: $bullDestinations,
            bullDestinationBatchId: isset($validated['bull_destination_batch_id']) ? (int) $validated['bull_destination_batch_id'] : null,
            scheduledMovementDate: $validated['scheduled_movement_date'] ?? null,
            remainingFemalesAction: isset($validated['remaining_females_action'])
                ? ServiceClosureRemainingFemalesAction::from((string) $validated['remaining_females_action'])
                : null,
            remainingFemalesTargetBatchId: isset($validated['remaining_females_target_batch_id']) ? (int) $validated['remaining_females_target_batch_id'] : null,
            observations: $validated['observations'] ?? null,
            notInseminatedReasons: self::notInseminatedReasons((array) ($validated['not_inseminated_reasons'] ?? []))
        );
    }

    /**
     * @param array<int, array{female_caravan_id: int, reason?: ?string}> $rows
     * @return array<int, ?NotInseminatedReason>
     */
    public static function notInseminatedReasons(array $rows): array
    {
        $reasons = [];
        foreach ($rows as $row) {
            $reasons[(int) $row['female_caravan_id']] = isset($row['reason']) ? NotInseminatedReason::tryFrom((string) $row['reason']) : null;
        }

        return $reasons;
    }
}
