<?php

declare(strict_types=1);

namespace App\Application\UseCases\TransferOrders;

use App\Core\Entities\TransferOrderEntity;
use App\Core\Exceptions\TransferOrderDomainException;
use App\Core\Interfaces\ITransferOrderRepository;

/**
 * Stamps `printed_at`. Reprinting is not a new fact: the first print is the one kept, and no
 * history line is written for a print — it is not a change of state.
 */
final class MarkTransferOrderPrintedUseCase
{
    public function __construct(private readonly ITransferOrderRepository $repository)
    {
    }

    /**
     * @throws TransferOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId): TransferOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw TransferOrderDomainException::notFound();

        // A draft is previewed, never printed: paper without an issued order is loose paper.
        if (!$order->getStatus()->isOpen() && $order->getStatus()->isEditable()) {
            throw TransferOrderDomainException::domainError(
                "La orden {$order->getCode()} es un borrador: emitila para imprimirla.",
                'DRAFT_NOT_PRINTABLE'
            );
        }

        if ($order->getPrintedAt() !== null) {
            return $order;
        }

        $order->markPrinted();

        return $this->repository->save($order, $userId);
    }
}
