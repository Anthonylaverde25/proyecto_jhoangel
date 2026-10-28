<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Application\DTOs\ExitOrders\IssueExitOrderDTO;
use App\Application\Services\CaravanExitRecorder;
use App\Application\Services\ExitBuyerGuard;
use App\Application\Services\ExitOrderCodeGenerator;
use App\Core\Entities\ExitOrderEntity;
use App\Core\Enums\ExitOrderKind;
use App\Core\Interfaces\IExitOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Emitir egreso" (PLANNED → ISSUED): the buyer, the planned date and the animals, which stay
 * committed to the order until they leave (E3).
 */
final class IssueExitOrderUseCase
{
    public function __construct(
        private readonly IExitOrderRepository $orders,
        private readonly ExitOrderCodeGenerator $codes,
        private readonly ExitBuyerGuard $buyers,
        private readonly CaravanExitRecorder $exits,
    ) {
    }

    public function __invoke(IssueExitOrderDTO $dto): ExitOrderEntity
    {
        return DB::transaction(function () use ($dto): ExitOrderEntity {
            $this->buyers->require($dto->buyerPartnerId, $dto->companyId);
            $labels = $this->exits->checkRoll($dto->caravanIds, $dto->companyId, null);

            $order = ExitOrderEntity::create(
                $dto->companyId,
                $this->codes->nextCode($dto->companyId, new \DateTimeImmutable()),
                ExitOrderKind::PLANNED,
                $dto->buyerPartnerId,
                $dto->exitDate,
                $dto->caravanIds,
                $dto->userId,
                $dto->responsable,
                $dto->observations,
                $labels,
            );

            return $this->orders->save($order, $dto->userId, null, ['head_count' => count($dto->caravanIds)]);
        });
    }
}
