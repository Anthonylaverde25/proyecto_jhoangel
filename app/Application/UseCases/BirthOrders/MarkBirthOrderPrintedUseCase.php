<?php

declare(strict_types=1);

namespace App\Application\UseCases\BirthOrders;

use App\Core\Entities\BirthOrderEntity;
use App\Core\Exceptions\BirthOrderDomainException;
use App\Core\Interfaces\IBirthOrderRepository;

/**
 * Stamps `printed_at`. Reprinting — the pending females before each round — is not a new fact: the
 * first print is the one kept, and no history line is written for a print.
 */
final class MarkBirthOrderPrintedUseCase
{
    public function __construct(private readonly IBirthOrderRepository $repository)
    {
    }

    /**
     * @throws BirthOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId): BirthOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw BirthOrderDomainException::notFound();

        // A draft is previewed, never printed: paper without an issued order is loose paper.
        if ($order->getStatus()->isEditable()) {
            throw BirthOrderDomainException::domainError(
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
