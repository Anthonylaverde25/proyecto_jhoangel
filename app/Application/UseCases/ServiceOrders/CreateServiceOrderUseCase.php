<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\ServiceOrders\CreateServiceOrderDTO;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Services\BullServiceFitnessPolicy;
use App\Core\Services\ServiceOrderCodeGenerator;
use App\Core\Services\ServiceStartPolicy;
use Illuminate\Support\Facades\DB;

final class CreateServiceOrderUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $repository,
        private readonly IBatchRepository $batchRepository,
        private readonly BullServiceFitnessPolicy $fitnessPolicy,
        private readonly ServiceStartPolicy $startPolicy,
        private readonly ServiceOrderCodeGenerator $codeGenerator,
        private readonly ICaravanRepository $caravanRepository
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(CreateServiceOrderDTO $dto): ServiceOrderEntity
    {
        // 0. The batch must be an own Cría batch without another active service, and the
        // females must already be in it: approving the order only brings the bulls in.
        $targetBatch = $this->batchRepository->findById($dto->batchId);
        if ($targetBatch === null) {
            throw ServiceOrderDomainException::domainError("Target batch with ID {$dto->batchId} not found");
        }

        $this->startPolicy->assertBatchCanStartService(
            $targetBatch,
            $this->repository->findActiveServiceOnBatch($dto->batchId) !== null
        );
        $this->startPolicy->assertHasBulls($dto->maleCaravanIds);

        $femaleCaravanIds = $this->startPolicy->resolveExposedFemales(
            $dto->isControlledService,
            $this->caravanRepository->findServiceableFemaleIdsInBatch($dto->batchId, $dto->isControlledService),
            $dto->femaleCaravanIds,
            $this->caravanRepository->findBreedingExcludedFemaleIdsInBatch($dto->batchId)
        );
        $allCaravans = array_merge($dto->maleCaravanIds, $femaleCaravanIds);

        // 1. Check if any caravan is already active in another service order
        $conflicts = $this->repository->findActiveOrdersByCaravans($allCaravans, $dto->companyId);
        if (!empty($conflicts)) {
            throw ServiceOrderDomainException::activeOrderConflict('caravan', reset($conflicts));
        }

        // 2. Verify existence, ownership, and sex of animals
        $sexes = $this->repository->verifyAnimalsSexAndOwnership($allCaravans, $dto->companyId);

        $sanitaryStatusByBull = [];
        foreach ($dto->maleCaravanIds as $maleId) {
            if (!isset($sexes[$maleId])) {
                throw ServiceOrderDomainException::domainError("Bull with ID {$maleId} not found or does not belong to the company");
            }
            if ($sexes[$maleId] !== AnimalSex::MALE->value) {
                throw ServiceOrderDomainException::invalidAnimalSex($maleId, 'male', $sexes[$maleId]);
            }

            // Admission guard: owner decision + veterinary verdict (F5 / ADR-5).
            $sanitaryStatusByBull[$maleId] = $this->fitnessPolicy->assertFit($maleId, $dto->companyId);
        }

        foreach ($femaleCaravanIds as $femaleId) {
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
            ->whereIn('caravans.id', $allCaravans)
            // A batch with a farm is external: every farm is a provider's (D21).
            ->whereNotNull('batches.farm_id')
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

                if (!in_array($femaleId, $femaleCaravanIds, true)) {
                    throw ServiceOrderDomainException::domainError("Assigned female with ID {$femaleId} is not in the list of females of this order");
                }

                if (!in_array($maleId, $dto->maleCaravanIds, true)) {
                    throw ServiceOrderDomainException::domainError("Assigned sire with ID {$maleId} is not in the list of bulls of this order");
                }

                $assignedFemales[] = $femaleId;
            }

            $uniqueAssignedFemales = array_unique($assignedFemales);
            if (count($uniqueAssignedFemales) !== count($femaleCaravanIds)) {
                throw ServiceOrderDomainException::domainError("All females must be assigned a sire in a controlled service");
            }
        }

        // 4-5. Instantiate the DRAFT entity and persist it; the code is taken inside the
        // transaction so the sequence lock covers the insert.
        return DB::transaction(function () use ($dto, $femaleCaravanIds, $sanitaryStatusByBull): ServiceOrderEntity {
            $entity = new ServiceOrderEntity(
                id: null,
                companyId: $dto->companyId,
                batchId: $dto->batchId,
                code: $this->codeGenerator->next($dto->companyId, $dto->plannedStartDate),
                status: ServiceOrderStatus::DRAFT,
                requestedByUserId: $dto->requestedByUserId,
                plannedStartDate: $dto->plannedStartDate,
                observations: $dto->observations,
                maleCaravanIds: $dto->maleCaravanIds,
                femaleCaravanIds: $femaleCaravanIds,
                serviceType: $dto->serviceType,
                isControlledService: $dto->isControlledService,
                femaleSireAssignments: $dto->femaleSireAssignments
            );

            $saved = $this->repository->save($entity, $dto->requestedByUserId);
            foreach ($sanitaryStatusByBull as $maleId => $status) {
                $this->repository->recordBullSanitaryStatusAtEntry((int) $saved->getId(), (int) $maleId, $status);
            }

            return $saved;
        });
    }
}
