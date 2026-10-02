<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Entities\EntryOrderEntity;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;

final class MarkEntryOrderPrintedUseCase
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    /**
     * @throws EntryOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId): EntryOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();
        $wasPrinted = $order->getPrintedAt() !== null;

        $order->markPrinted();

        return $this->repository->save($order, $userId, null, $wasPrinted ? null : ['action' => 'printed']);
    }
}
