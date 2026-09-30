<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningOrders;

use App\Core\Entities\WeaningOrderEntity;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\IWeaningOrderRepository;

/**
 * Stamps `printed_at`. Reprinting is not a new fact: the first print is the one kept, and no history
 * line is written for a print.
 */
final class MarkWeaningOrderPrintedUseCase
{
    public function __construct(private readonly IWeaningOrderRepository $repository)
    {
    }

    /**
     * @throws WeaningOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId): WeaningOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw WeaningOrderDomainException::notFound();

        // A draft is previewed, never printed: paper without an issued order is loose paper.
        if ($order->getStatus()->isEditable()) {
            throw WeaningOrderDomainException::domainError(
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
