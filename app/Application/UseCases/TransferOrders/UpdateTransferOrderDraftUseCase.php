<?php

declare(strict_types=1);

namespace App\Application\UseCases\TransferOrders;

use App\Application\DTOs\TransferOrders\EmitTransferOrderDTO;
use App\Application\Services\TransferOrderRosterBuilder;
use App\Core\Entities\TransferOrderEntity;
use App\Core\Exceptions\TransferOrderDomainException;
use App\Core\Interfaces\ITransferOrderRepository;

/**
 * "Guardar cambios" on a draft: replaces its destination activity, destinations and roll with
 * what the screen shows now. The source batch is the draft's and does not change.
 */
final class UpdateTransferOrderDraftUseCase
{
    public function __construct(
        private readonly ITransferOrderRepository $repository,
        private readonly TransferOrderRosterBuilder $roster
    ) {
    }

    /**
     * @throws TransferOrderDomainException
     */
    public function __invoke(int $id, EmitTransferOrderDTO $dto): TransferOrderEntity
    {
        $order = $this->repository->findById($id, $dto->companyId) ?? throw TransferOrderDomainException::notFound();

        if ($order->getSourceBatchId() !== $dto->sourceBatchId) {
            throw TransferOrderDomainException::domainError('Un borrador no cambia de lote de origen.', 'SOURCE_BATCH_CHANGED');
        }

        [$destinations, $animals] = $this->roster->build($dto, $this->roster->sourceBatch($dto->sourceBatchId));

        $order->updateDraft($dto->destinationActivityId, $dto->destinationMode, $destinations, $animals, $dto->categoryMode);

        return $this->repository->save($order, $dto->requestedByUserId);
    }
}
