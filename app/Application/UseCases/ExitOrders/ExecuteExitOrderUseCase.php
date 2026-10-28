<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Application\DTOs\ExitOrders\ExecuteExitOrderDTO;
use App\Application\Services\CaravanExitRecorder;
use App\Core\Entities\ExitOrderEntity;
use App\Core\Interfaces\IExitOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * One load of an issued order left (ISSUED/PARTIAL → PARTIAL/EXECUTED). All or nothing per load
 * (E8): an animal that cannot leave leaves the whole load unwritten, with every row's error.
 */
final class ExecuteExitOrderUseCase
{
    public function __construct(
        private readonly IExitOrderRepository $orders,
        private readonly ExitOrderOfCompany $orderOfCompany,
        private readonly CaravanExitRecorder $exits,
        private readonly AddExitOrderDteUseCase $dtes,
    ) {
    }

    public function __invoke(ExecuteExitOrderDTO $dto): ExitOrderEntity
    {
        return DB::transaction(function () use ($dto): ExitOrderEntity {
            $order = ($this->orderOfCompany)($dto->orderId, $dto->companyId);

            if ($dto->dte !== null) {
                $order->assertAcceptsDte();
            }

            $exits = $this->exits->record(
                $order,
                $dto->lines,
                $dto->exitDate,
                $dto->exitBatchId,
                ExitObservation::of($order)
            );
            $order->recordExits($dto->exitDate, $exits);
            $order = $this->orders->save($order, $dto->userId, null, ['exited' => count($exits), 'exit_date' => $dto->exitDate]);

            if ($dto->dte !== null) {
                $this->dtes->add($order, $dto->dte, $dto->userId);
                $order = ($this->orderOfCompany)($dto->orderId, $dto->companyId);
            }

            return $order;
        });
    }
}
