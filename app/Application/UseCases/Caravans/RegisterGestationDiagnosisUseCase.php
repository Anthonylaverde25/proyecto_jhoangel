<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\RegisterGestationDiagnosisDTO;
use App\Core\Entities\CaravanEntity;
use App\Core\Enums\AnimalCategory;
use App\Core\Enums\GestationStage;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\ValueObjects\FemaleReproductiveDetails;
use App\Core\ValueObjects\SireEntry;

final class RegisterGestationDiagnosisUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly IServiceOrderRepository $serviceOrderRepository
    ) {
    }

    /**
     * Execute the gestation diagnosis.
     *
     * @throws DomainException
     */
    public function __invoke(RegisterGestationDiagnosisDTO $dto, int $companyId): CaravanEntity
    {
        $caravan = $this->caravanRepository->findById($dto->caravanId);
        if ($caravan === null) {
            throw new DomainException("Caravan not found.");
        }

        if ($caravan->getCompanyId() !== $companyId) {
            throw new DomainException("Caravan does not belong to the active company.");
        }

        $serviceOrder = $this->serviceOrderRepository->findById($dto->serviceOrderId, $companyId);
        if ($serviceOrder === null) {
            throw new DomainException("Service order not found.");
        }

        // Validate that the caravan belongs to this service order's females list
        if (!in_array($dto->caravanId, $serviceOrder->getFemaleCaravanIds(), true)) {
            throw new DomainException("This caravan does not belong to the selected service order.");
        }

        if ($dto->isPregnant) {
            // Update reproductive details to not empty
            $category = $caravan->getCategory() ?? AnimalCategory::VACA;
            $caravan->recordFemaleDetails(new FemaleReproductiveDetails(false, $category));

            // Determine stage and months
            [$stage, $months] = $this->resolveGestationDetails($dto->gestationStage, $dto->gestationMonths);

            // Start gestation linked to the service order
            $caravan->startNewGestation($dto->diagnosisDate, $stage, $months, $dto->serviceOrderId);

            // Populate sires from the service order
            $activeGestation = $caravan->getActiveGestation();
            if ($activeGestation !== null) {
                $maleIds = $serviceOrder->getMaleCaravanIds();
                foreach ($maleIds as $maleId) {
                    $isConfirmed = ($dto->confirmedSireId !== null && $maleId === $dto->confirmedSireId);
                    // If there is only one male in the order, confirm it automatically
                    if (count($maleIds) === 1) {
                        $isConfirmed = true;
                    }
                    $activeGestation->addSire(new SireEntry($maleId, '', $isConfirmed));
                }
            }
        } else {
            // Update reproductive details to empty
            $category = $caravan->getCategory() ?? AnimalCategory::VACA;
            $caravan->recordFemaleDetails(new FemaleReproductiveDetails(true, $category));

            // Relocate to the empty destination batch if provided
            if ($dto->emptyDestinationBatchId !== null) {
                $caravan->moveToBatch($dto->emptyDestinationBatchId);
            }

            // Close active gestation if exists
            if ($caravan->hasActiveGestation()) {
                $activeGestation = $caravan->getActiveGestation();
                if ($activeGestation !== null) {
                    $activeGestation->closeGestation(
                        success: false,
                        endDate: $dto->diagnosisDate ?? date('Y-m-d'),
                        notes: 'Marked empty via gestation diagnosis'
                    );
                }
            }
        }

        return $this->caravanRepository->save($caravan);
    }

    /**
     * Resolve gestation stage and months bidirectionally.
     *
     * @return array{0: GestationStage, 1: float}
     */
    private function resolveGestationDetails(?string $stageStr, ?float $months): array
    {
        if ($months !== null) {
            $stage = GestationStage::fromMonths($months);
            return [$stage, $months];
        }

        if ($stageStr !== null) {
            $stage = GestationStage::from($stageStr);
            return [$stage, $stage->toDefaultMonths()];
        }

        return [GestationStage::HEAD, 3.0];
    }
}
