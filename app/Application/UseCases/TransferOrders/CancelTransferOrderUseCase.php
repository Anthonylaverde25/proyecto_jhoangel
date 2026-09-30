<?php

declare(strict_types=1);

namespace App\Application\UseCases\TransferOrders;

use App\Core\Entities\TransferOrderEntity;
use App\Core\Exceptions\TransferOrderDomainException;
use App\Core\Interfaces\ITransferOrderRepository;

/**
 * Cancels an order nothing was executed against: discarding a draft (reason optional) or
 * cancelling an issued order (reason required). It is also what "Anular y volver a armar" does:
 * the order is gone with its reason on record, and the screen goes back to editing.
 */
final class CancelTransferOrderUseCase
{
    public function __construct(private readonly ITransferOrderRepository $repository)
    {
    }

    /**
     * @throws TransferOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, ?string $reason): TransferOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw TransferOrderDomainException::notFound();

        $order->cancel($reason);

        return $this->repository->save($order, $userId, $order->getClosingReason());
    }
}
