<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Entities\EntryOrderEntity;
use App\Core\Enums\ReferenceMode;
use App\Core\Enums\WeighingMode;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;

/**
 * What an ING-03 sheet prints, while it has not gone out on paper: how it is weighed — per animal
 * or one average — and how each line names its breed, coat and category — in words or by code.
 * Each change is a line of the order's history.
 */
final class ConfigureReceiptSheetUseCase
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    /**
     * @throws EntryOrderDomainException
     */
    public function __invoke(
        int $id,
        int $companyId,
        ?int $userId,
        int $sheetId,
        ?WeighingMode $weighingMode,
        ?ReferenceMode $referenceMode
    ): EntryOrderEntity {
        $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();
        $sheet = $order->findReceiptSheet($sheetId);
        $changes = [];

        if ($weighingMode !== null && $weighingMode !== $sheet->getWeighingMode()) {
            $sheet->changeWeighingMode($weighingMode);
            $changes[] = [
                'action' => 'receipt_sheet_weighing_changed',
                'weighing_mode' => $weighingMode->value,
                'weighing_mode_label' => $weighingMode->label(),
            ];
        }

        if ($referenceMode !== null && $referenceMode !== $sheet->getReferenceMode()) {
            $sheet->changeReferenceMode($referenceMode);
            $changes[] = [
                'action' => 'receipt_sheet_reference_changed',
                'reference_mode' => $referenceMode->value,
                'reference_mode_label' => $referenceMode->label(),
            ];
        }

        if ($changes === []) {
            return $order;
        }

        // One save, one history line: both changes travel together when the toolbar sends both.
        return $this->repository->save($order, $userId, null, [
            ...array_merge(...$changes),
            'action' => count($changes) === 1 ? $changes[0]['action'] : 'receipt_sheet_configured',
            'receipt_sheet' => $sheet->label(),
            'dte_number' => $sheet->getDteNumber(),
        ]);
    }
}
