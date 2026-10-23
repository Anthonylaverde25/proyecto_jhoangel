<?php

declare(strict_types=1);

namespace App\Application\DTOs\ServiceOrders;

use App\Application\DTOs\RegisterGestationDiagnosisDTO;
use App\Core\Enums\NotInseminatedReason;
use App\Core\Enums\ServiceClosureBullAction;
use App\Core\Enums\ServiceClosureRemainingFemalesAction;

/**
 * "Cerrar orden": the verdicts still missing (step 1), then the decision on the bulls still in
 * the batch and on the non-pregnant females that would stay with them (step 3).
 */
final readonly class CloseServiceOrderDiagnosisDTO
{
    /**
     * @param RegisterGestationDiagnosisDTO[] $diagnoses
     * @param list<array{female_caravan_id: int, reason: string, notes: ?string}> $notDiagnosed
     * @param array<int, int> $bullDestinations male_caravan_id => destination batch id (bulls leaving now)
     * @param array<int, ?NotInseminatedReason> $notInseminatedReasons AI only: optional note per female never inseminated (IA12)
     */
    public function __construct(
        public int $serviceOrderId,
        public int $companyId,
        public ?int $userId,
        public string $closingDate,
        public string $roundDate,
        public array $diagnoses = [],
        public array $notDiagnosed = [],
        public ?int $emptiesTargetBatchId = null,
        public ?ServiceClosureBullAction $bullAction = null,
        public array $bullDestinations = [],
        public ?int $bullDestinationBatchId = null,
        public ?string $scheduledMovementDate = null,
        public ?ServiceClosureRemainingFemalesAction $remainingFemalesAction = null,
        public ?int $remainingFemalesTargetBatchId = null,
        public ?string $observations = null,
        public array $notInseminatedReasons = []
    ) {
    }
}
