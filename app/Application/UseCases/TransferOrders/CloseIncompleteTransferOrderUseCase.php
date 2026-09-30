<?php

declare(strict_types=1);

namespace App\Application\UseCases\TransferOrders;

use App\Core\Entities\TransferOrderEntity;
use App\Core\Exceptions\TransferOrderDomainException;
use App\Core\Interfaces\ITransferOrderRepository;

final class CloseIncompleteTransferOrderUseCase
{
    public function __construct(private readonly ITransferOrderRepository $repository)
    {
    }

    /**
     * @throws TransferOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, string $reason): TransferOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw TransferOrderDomainException::notFound();
        $pending = $order->pendingCount();

        $order->closeIncomplete($reason);

        return $this->repository->save($order, $userId, trim($reason), ['skipped' => $pending]);
    }
}
