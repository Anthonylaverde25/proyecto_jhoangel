<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\ServiceOrders\CreateServiceOrderDTO;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IBullHealthEvaluationRepository;
use App\Core\Interfaces\IServiceOrderRepository;

final class CreateServiceOrderUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $repository,
        private readonly IBatchRepository $batchRepository,
        private readonly IBullHealthEvaluationRepository $bullHealthRepository
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(CreateServiceOrderDTO $dto): ServiceOrderEntity
    {
        $allCaravans = array_merge($dto->maleCaravanIds, $dto->femaleCaravanIds);
        
        if (empty($dto->maleCaravanIds)) {
            throw ServiceOrderDomainException::domainError("Cannot create a service order without bulls");
        }

        if (empty($dto->femaleCaravanIds)) {
            throw ServiceOrderDomainException::domainError("Cannot create a service order without females");
        }

        // 0. Validate Target Batch Activity (Must be 'CRIA') and Ownership (Must be internal/own batch)
        $targetBatch = $this->batchRepository->findById($dto->batchId);
        if ($targetBatch === null) {
            throw ServiceOrderDomainException::domainError("Target batch with ID {$dto->batchId} not found");
        }

        if ($targetBatch->getFarmId() !== null) {
            throw ServiceOrderDomainException::domainError("The selected target batch must be an internal (own) batch.");
        }

        $activityCode = strtoupper((string) ($targetBatch->getActivityCode() ?? ''));
        $activityName = mb_strtolower((string) ($targetBatch->getActivityName() ?? ''));

        if ($activityCode !== 'CRIA' && !str_contains($activityName, 'cría') && !str_contains($activityName, 'cria')) {
            throw ServiceOrderDomainException::domainError("The selected target batch must belong to the 'CRIA' (Breeding) activity.");
        }

        // 1. Check if any caravan is already active in another service order
        $conflicts = $this->repository->findActiveOrdersByCaravans($allCaravans, $dto->companyId);
        if (!empty($conflicts)) {
            throw ServiceOrderDomainException::activeOrderConflict('caravan', reset($conflicts));
        }

        // 2. Verify existence, ownership, and sex of animals
        $sexes = $this->repository->verifyAnimalsSexAndOwnership($allCaravans, $dto->companyId);

        foreach ($dto->maleCaravanIds as $maleId) {
            if (!isset($sexes[$maleId])) {
                throw ServiceOrderDomainException::domainError("Bull with ID {$maleId} not found or does not belong to the company");
            }
            if ($sexes[$maleId] !== AnimalSex::MALE->value) {
                throw ServiceOrderDomainException::invalidAnimalSex($maleId, 'male', $sexes[$maleId]);
            }

            // Andrological & Clinical Health Guard
            $bullHealth = $this->bullHealthRepository->findByCaravanId($maleId, $dto->companyId);
            if ($bullHealth !== null && !$bullHealth->isApt()) {
                $statusLabel = $bullHealth->getStatus()->value;
                $activeDiags = array_map(fn ($d) => $d->getPathogenName() ?? $d->getPathogenCode(), $bullHealth->getActiveDiagnoses());
                $diagText = !empty($activeDiags) ? ' (' . implode(', ', $activeDiags) . ')' : '';
                throw ServiceOrderDomainException::domainError(
                    "El reproductor ID {$maleId} no está apto para servicio. Estado: {$statusLabel}{$diagText}."
                );
            }
        }

        foreach ($dto->femaleCaravanIds as $femaleId) {
            if (!isset($sexes[$femaleId])) {
                throw ServiceOrderDomainException::domainError("Female with ID {$femaleId} not found or does not belong to the company");
            }
            if ($sexes[$femaleId] !== AnimalSex::FEMALE->value) {
                throw ServiceOrderDomainException::invalidAnimalSex($femaleId, 'female', $sexes[$femaleId]);
            }
        }

        // 2.5. Verify that no animal belongs to an external batch (pending own assignment)
        $invalidCaravans = \Illuminate\Support\Facades\DB::table('caravans')
            ->join('batches', 'caravans.batch_id', '=', 'batches.id')
            ->leftJoin('farms', 'batches.farm_id', '=', 'farms.id')
            ->whereIn('caravans.id', $allCaravans)
            ->where(function ($query) {
                $query->whereNotNull('farms.provider_id');
            })
            ->select('caravans.id', 'caravans.identification', 'batches.name as batch_name')
            ->get()
            ->map(fn($row) => [
                'id' => (int) $row->id,
                'identification' => (string) $row->identification,
                'batch_name' => (string) $row->batch_name
            ])
            ->toArray();

        if (!empty($invalidCaravans)) {
            throw \App\Core\Exceptions\CaravanInExternalBatchException::forCaravans($invalidCaravans);
        }

        // 3. Multi-Toro / Controlled Service Validations
        if ($dto->serviceType === 'multi' && $dto->isControlledService) {
            $assignedFemales = [];
            foreach ($dto->femaleSireAssignments as $assignment) {
                $femaleId = isset($assignment['female_caravan_id']) ? (int) $assignment['female_caravan_id'] : null;
                $maleId = isset($assignment['assigned_male_caravan_id']) ? (int) $assignment['assigned_male_caravan_id'] : null;

                if ($femaleId === null || $maleId === null) {
                    throw ServiceOrderDomainException::domainError("Invalid structure for female-sire assignments");
                }

                if (!in_array($femaleId, $dto->femaleCaravanIds, true)) {
                    throw ServiceOrderDomainException::domainError("Assigned female with ID {$femaleId} is not in the list of females of this order");
                }

                if (!in_array($maleId, $dto->maleCaravanIds, true)) {
                    throw ServiceOrderDomainException::domainError("Assigned sire with ID {$maleId} is not in the list of bulls of this order");
                }

                $assignedFemales[] = $femaleId;
            }

            $uniqueAssignedFemales = array_unique($assignedFemales);
            if (count($uniqueAssignedFemales) !== count($dto->femaleCaravanIds)) {
                throw ServiceOrderDomainException::domainError("All females must be assigned a sire in a controlled service");
            }
        }

        // 4. Instantiate Entity in DRAFT state
        $entity = new ServiceOrderEntity(
            id: null,
            companyId: $dto->companyId,
            batchId: $dto->batchId,
            code: $dto->code,
            status: ServiceOrderStatus::DRAFT,
            requestedByUserId: $dto->requestedByUserId,
            plannedStartDate: $dto->plannedStartDate,
            observations: $dto->observations,
            maleCaravanIds: $dto->maleCaravanIds,
            femaleCaravanIds: $dto->femaleCaravanIds,
            serviceType: $dto->serviceType,
            isControlledService: $dto->isControlledService,
            femaleSireAssignments: $dto->femaleSireAssignments
        );

        // 5. Persist
        return $this->repository->save($entity, $dto->requestedByUserId);
    }
}
