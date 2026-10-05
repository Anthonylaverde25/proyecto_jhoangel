<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Entities\EntryOrderEntity;
use App\Core\Enums\WeighingMode;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;

/**
 * Issues the ING-03 receipt sheet of a DTE: the paper its caravans in transit are received on at
 * the chute, weighed animal by animal or with one average (by default, like the last sheet). An
 * earlier sheet of the same DTE still out is replaced, and the history records it.
 */
final class IssueReceiptSheetUseCase
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    /**
     * @return array{order: EntryOrderEntity, sheet_number: int}
     *
     * @throws EntryOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, int $dteId, ?WeighingMode $weighingMode = null): array
    {
        $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();
        $sheet = $order->issueReceiptSheet($dteId, $userId, $weighingMode);

        $saved = $this->repository->save($order, $userId, null, [
            'action' => 'receipt_sheet_issued',
            'receipt_sheet' => $sheet->label(),
            'dte_number' => $sheet->getDteNumber(),
            'caravans' => count($sheet->getCaravanIds()),
            'weighing_mode' => $sheet->getWeighingMode()->value,
            'weighing_mode_label' => $sheet->getWeighingMode()->label(),
        ]);

        return ['order' => $saved, 'sheet_number' => $sheet->getNumber()];
    }
}
