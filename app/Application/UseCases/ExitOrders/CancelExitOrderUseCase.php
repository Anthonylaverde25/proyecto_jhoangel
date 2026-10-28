<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Core\Entities\ExitOrderEntity;
use App\Core\Interfaces\IExitOrderRepository;
use Illuminate\Support\Facades\DB;

/** Cancels an issued order from which nothing left; its animals are free again. */
final class CancelExitOrderUseCase
{
    public function __construct(
        private readonly IExitOrderRepository $orders,
        private readonly ExitOrderOfCompany $orderOfCompany,
    ) {
    }

    public function __invoke(int $orderId, string $reason, int $companyId, ?int $userId): ExitOrderEntity
    {
        return DB::transaction(function () use ($orderId, $reason, $companyId, $userId): ExitOrderEntity {
            $order = ($this->orderOfCompany)($orderId, $companyId);
            $order->cancel($reason);

            return $this->orders->save($order, $userId, $reason);
        });
    }
}
