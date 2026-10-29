<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Application\DTOs\ExitOrders\IssueExitOrderDTO;
use App\Application\Services\CaravanExitRecorder;
use App\Application\Services\ExitBuyerGuard;
use App\Core\Entities\ExitOrderEntity;
use App\Core\Interfaces\IExitOrderRepository;
use Illuminate\Support\Facades\DB;

/** Edits an issued order from which nothing left yet: buyer, date and animals (01 §3). */
final class UpdateExitOrderUseCase
{
    public function __construct(
        private readonly IExitOrderRepository $orders,
        private readonly ExitOrderOfCompany $orderOfCompany,
        private readonly ExitBuyerGuard $buyers,
        private readonly CaravanExitRecorder $exits,
    ) {
    }

    public function __invoke(int $orderId, IssueExitOrderDTO $dto): ExitOrderEntity
    {
        return DB::transaction(function () use ($orderId, $dto): ExitOrderEntity {
            $order = ($this->orderOfCompany)($orderId, $dto->companyId);
            $this->buyers->require($dto->buyerPartnerId, $dto->companyId);
            $labels = $this->exits->checkRoll($dto->caravanIds, $dto->companyId, $order->getCode());

            $order->replaceRoll($dto->buyerPartnerId, $dto->exitDate, $dto->caravanIds, $dto->responsable, $dto->observations, $labels);
            $order->agreePrices($dto->agreedPrices);

            return $this->orders->save($order, $dto->userId, 'Orden editada', ['head_count' => count($dto->caravanIds)]);
        });
    }
}
