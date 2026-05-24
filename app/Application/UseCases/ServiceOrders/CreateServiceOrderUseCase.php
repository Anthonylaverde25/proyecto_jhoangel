<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\ServiceOrders\CreateServiceOrderDTO;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;

final class CreateServiceOrderUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $repository
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
        }

        foreach ($dto->femaleCaravanIds as $femaleId) {
            if (!isset($sexes[$femaleId])) {
                throw ServiceOrderDomainException::domainError("Female with ID {$femaleId} not found or does not belong to the company");
            }
            if ($sexes[$femaleId] !== AnimalSex::FEMALE->value) {
                throw ServiceOrderDomainException::invalidAnimalSex($femaleId, 'female', $sexes[$femaleId]);
            }
        }

        // 3. Instantiate Entity in DRAFT state
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
            femaleCaravanIds: $dto->femaleCaravanIds
        );

        // 4. Persist
        return $this->repository->save($entity, $dto->requestedByUserId);
    }
}
