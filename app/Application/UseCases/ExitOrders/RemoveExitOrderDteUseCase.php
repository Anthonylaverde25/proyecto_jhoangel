<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Core\Interfaces\IExitOrderRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Takes off a DTE loaded by mistake; the history keeps that it was there. */
final class RemoveExitOrderDteUseCase
{
    public function __construct(
        private readonly IExitOrderRepository $orders,
        private readonly ExitOrderOfCompany $orderOfCompany,
    ) {
    }

    public function __invoke(int $orderId, int $dteId, int $companyId, ?int $userId): void
    {
        $order = ($this->orderOfCompany)($orderId, $companyId);

        if (!$this->orders->removeDte($order, $dteId, $userId)) {
            throw new ModelNotFoundException("DTE {$dteId} not found.");
        }
    }
}
