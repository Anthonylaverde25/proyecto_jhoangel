<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Interfaces\ICaravanRepository;

final class CompleteServiceOrderUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $repository,
        private readonly ICaravanRepository $caravanRepository
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(int $id, int $companyId, int $userId, ?string $observations = null, ?int $targetBatchId = null): ServiceOrderEntity
    {
        $entity = $this->repository->findById($id, $companyId);
        if ($entity === null) {
            throw ServiceOrderDomainException::domainError("Service order not found");
        }

        // 1. Transfer unpregnant females to target batch if targetBatchId is provided
        if ($targetBatchId !== null) {
            foreach ($entity->getFemaleCaravanIds() as $femaleId) {
                $caravan = $this->caravanRepository->findById($femaleId);
                if ($caravan !== null && !$caravan->hasActiveGestation()) {
                    $this->caravanRepository->updateBatchAndCategory($femaleId, $targetBatchId, $caravan->getCategoryId(), $caravan->getSubcategoryId());
                }
            }
        }

        // 2. Validate that all female caravans remaining in the service batch have an active gestation
        foreach ($entity->getFemaleCaravanIds() as $femaleId) {
            $caravan = $this->caravanRepository->findById($femaleId);
            if ($caravan === null) {
                throw ServiceOrderDomainException::domainError("Caravan with ID {$femaleId} not found.");
            }
            if ($caravan->getBatchId() === $entity->getBatchId() && !$caravan->hasActiveGestation()) {
                throw ServiceOrderDomainException::domainError(
                    "Cannot close service order. Caravan {$caravan->getIdentification()->getValue()} is empty and remains in the service batch. You must move it to another batch."
                );
            }
        }

        $entity->complete($observations);

        return $this->repository->save($entity, $userId);
    }
}
