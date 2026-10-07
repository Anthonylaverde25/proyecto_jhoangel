<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Entities\EntryOrderEntity;
use App\Core\Enums\ReferenceMode;
use App\Core\Enums\WeighingMode;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;

/**
 * Issues the ING-03 receipt sheet of a DTE: the paper its head in transit are received on at the
 * chute, one blank line each, weighed animal by animal or with one average, its breed and category
 * written or by code (by default, like the last sheet). An
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
    public function __invoke(
        int $id,
        int $companyId,
        ?int $userId,
        int $dteId,
        ?WeighingMode $weighingMode = null,
        ?ReferenceMode $referenceMode = null
    ): array
    {
        $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();
        $sheet = $order->issueReceiptSheet($dteId, $userId, $weighingMode, $referenceMode);

        $saved = $this->repository->save($order, $userId, null, [
            'action' => 'receipt_sheet_issued',
            'receipt_sheet' => $sheet->label(),
            'dte_number' => $sheet->getDteNumber(),
            'expected_head_count' => $sheet->getExpectedHeadCount(),
            'weighing_mode' => $sheet->getWeighingMode()->value,
            'weighing_mode_label' => $sheet->getWeighingMode()->label(),
            'reference_mode' => $sheet->getReferenceMode()->value,
            'reference_mode_label' => $sheet->getReferenceMode()->label(),
        ]);

        return ['order' => $saved, 'sheet_number' => $sheet->getNumber()];
    }
}
