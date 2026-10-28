<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Application\DTOs\ExitOrders\RegisterExitDTO;
use App\Application\Services\CaravanExitRecorder;
use App\Application\Services\ExitBuyerGuard;
use App\Application\Services\ExitOrderCodeGenerator;
use App\Core\Entities\ExitOrderEntity;
use App\Core\Enums\ExitOrderKind;
use App\Core\Interfaces\IExitOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Registrar egreso" and the quick "Vender": an exit that already happened, as an order born
 * executed (REGISTERED). The DTE, when in hand, comes along; without it the order is marked
 * "Sin DTE" and nothing is blocked.
 */
final class RegisterExitUseCase
{
    public function __construct(
        private readonly IExitOrderRepository $orders,
        private readonly ExitOrderCodeGenerator $codes,
        private readonly ExitBuyerGuard $buyers,
        private readonly CaravanExitRecorder $exits,
        private readonly AddExitOrderDteUseCase $dtes,
        private readonly ExitOrderOfCompany $orderOfCompany,
    ) {
    }

    public function __invoke(RegisterExitDTO $dto): ExitOrderEntity
    {
        return DB::transaction(function () use ($dto): ExitOrderEntity {
            $buyer = $this->buyers->require($dto->buyerPartnerId, $dto->companyId);
            $caravanIds = array_map(fn ($line) => $line->caravanId, $dto->lines);

            $order = ExitOrderEntity::create(
                $dto->companyId,
                $this->codes->nextCode($dto->companyId, new \DateTimeImmutable()),
                ExitOrderKind::REGISTERED,
                $dto->buyerPartnerId,
                $dto->exitDate,
                $caravanIds,
                $dto->userId,
                $dto->responsable,
                $dto->observations,
            );

            $exits = $this->exits->record(
                $order,
                $dto->lines,
                $dto->exitDate,
                $dto->exitBatchId,
                ExitObservation::of($order, $buyer->getName())
            );
            $order->recordExits($dto->exitDate, $exits);
            $order = $this->orders->save($order, $dto->userId, null, ['exited' => count($exits), 'exit_date' => $dto->exitDate]);

            if ($dto->dte !== null) {
                $this->dtes->add($order, $dto->dte, $dto->userId);
                $order = ($this->orderOfCompany)((int) $order->getId(), $dto->companyId);
            }

            return $order;
        });
    }
}
