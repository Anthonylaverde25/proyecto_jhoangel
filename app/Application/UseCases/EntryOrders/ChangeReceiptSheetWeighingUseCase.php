<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Entities\EntryOrderEntity;
use App\Core\Enums\WeighingMode;
use App\Core\Exceptions\EntryOrderDomainException;
use App\Core\Interfaces\IEntryOrderRepository;

/**
 * How an ING-03 sheet is weighed — per animal or one average — while it has not gone out on paper.
 */
final class ChangeReceiptSheetWeighingUseCase
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    /**
     * @throws EntryOrderDomainException
     */
    public function __invoke(int $id, int $companyId, ?int $userId, int $sheetId, WeighingMode $weighingMode): EntryOrderEntity
    {
        $order = $this->repository->findById($id, $companyId) ?? throw EntryOrderDomainException::notFound();
        $sheet = $order->findReceiptSheet($sheetId);

        if ($sheet->getWeighingMode() === $weighingMode) {
            return $order;
        }

        $sheet->changeWeighingMode($weighingMode);

        return $this->repository->save($order, $userId, null, [
            'action' => 'receipt_sheet_weighing_changed',
            'receipt_sheet' => $sheet->label(),
            'dte_number' => $sheet->getDteNumber(),
            'weighing_mode' => $weighingMode->value,
            'weighing_mode_label' => $weighingMode->label(),
        ]);
    }
}
