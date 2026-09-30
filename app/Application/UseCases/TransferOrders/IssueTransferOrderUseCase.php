<?php

declare(strict_types=1);

namespace App\Application\UseCases\TransferOrders;

use App\Application\Services\TransferOrderRosterBuilder;
use App\Core\Entities\TransferOrderEntity;
use App\Core\Exceptions\TransferOrderDomainException;
use App\Core\Interfaces\ITransferOrderRepository;

/**
 * "Emitir orden": draft → issued. The draft may be days old, so what it names is checked again —
 * the animals are still in the source batch and no other open order took them meanwhile.
 */
final class IssueTransferOrderUseCase
{
    public function __construct(
        private readonly ITransferOrderRepository $repository,
        private readonly TransferOrderRosterBuilder $roster
    ) {
    }

    /**
     * @throws TransferOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId): TransferOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw TransferOrderDomainException::notFound();
        $caravanIds = array_map(fn ($line) => $line->getCaravanId(), $order->getAnimals());

        $sourceBatch = $this->roster->sourceBatch($order->getSourceBatchId());
        $this->roster->assertInSourceBatch($caravanIds, $companyId, (int) $sourceBatch->getId(), $sourceBatch->getName());
        $this->roster->assertNotCommitted($caravanIds, $companyId);

        $order->issue();

        return $this->repository->save($order, $userId, 'Borrador emitido');
    }
}
