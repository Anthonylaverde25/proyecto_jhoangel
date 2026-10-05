<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Entities\EntryOrderEntity;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;

/**
 * The ING-03 sheet went out on paper: printed or downloaded, never just opened.
 */
final class MarkReceiptSheetPrintedUseCase
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    /**
     * @throws EntryOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, int $sheetId): EntryOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();
        $order->findReceiptSheet($sheetId)->markPrinted();

        return $this->repository->save($order, $userId);
    }
}
