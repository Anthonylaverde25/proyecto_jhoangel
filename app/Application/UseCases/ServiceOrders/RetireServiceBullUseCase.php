<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\ServiceOrders\RetireServiceBullDTO;
use App\Application\Services\ServiceBullRetirement;
use App\Application\UseCases\Veterinary\RaiseSanitaryExposureAlertUseCase;
use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\ServiceOrderMaleStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Services\BatchWeightService;
use App\Models\ServiceOrderHistory;
use App\Models\ServiceOrderMale;
use Illuminate\Support\Facades\DB;

/**
 * Takes a bull out of a running service without bringing another one in: there may be no
 * spare bull when he must leave at once (a venereal positive, an injury). The order stays
 * open; if he was the last active bull the response warns, it does not block.
 */
final class RetireServiceBullUseCase
{
    public function __construct(
        private readonly ServiceBullRetirement $retirement,
        private readonly BatchWeightService $batchWeightService,
        private readonly RaiseSanitaryExposureAlertUseCase $raiseExposureAlert
    ) {
    }

    /**
     * @return array{remaining_active_bulls: int, warning: string|null}
     *
     * @throws ServiceOrderDomainException
     */
    public function __invoke(RetireServiceBullDTO $dto): array
    {
        [$serviceOrder, $bull, $serviceMale] = $this->retirement->loadActiveBull(
            $dto->serviceOrderId,
            $dto->maleCaravanId,
            $dto->companyId
        );

        $destinationBatchId = $this->retirement->resolveDestination(
            $serviceOrder,
            $bull,
            $dto->reason,
            $dto->destinationBatchId,
            $dto->companyId
        );

        $remaining = DB::transaction(function () use ($dto, $serviceOrder, $bull, $serviceMale, $destinationBatchId): int {
            $this->retirement->retire(
                $serviceOrder,
                $bull,
                $serviceMale,
                $dto->reason,
                $dto->retirementDate,
                $destinationBatchId,
                $dto->notes,
                $dto->companyId
            );

            $servicedBatchId = (int) $serviceOrder->batch_id;
            $this->batchWeightService->recalculateBatchWeight($servicedBatchId, BatchWeightCause::MOVEMENT_OUT);
            if ($destinationBatchId !== null && $destinationBatchId !== $servicedBatchId) {
                $this->batchWeightService->recalculateBatchWeight($destinationBatchId, BatchWeightCause::MOVEMENT_IN);
            }

            ServiceOrderHistory::create([
                'company_id'       => $dto->companyId,
                'service_order_id' => $serviceOrder->id,
                'action_user_id'   => $dto->userId,
                'from_status'      => $serviceOrder->status,
                'to_status'        => $serviceOrder->status,
                'action_reason'    => "Retiro de reproductor sin reemplazo: #{$bull->identification} por {$dto->reason->label()}" .
                    ($dto->notes ? ": {$dto->notes}" : '') . '.',
            ]);

            return ServiceOrderMale::withoutGlobalScopes()
                ->where('service_order_id', $serviceOrder->id)
                ->where('status', ServiceOrderMaleStatus::ACTIVE->value)
                ->count();
        });

        // An open exposure alert of this bull now knows when he left the females.
        ($this->raiseExposureAlert)((int) $bull->id, $dto->companyId);

        return [
            'remaining_active_bulls' => $remaining,
            'warning' => $remaining === 0
                ? "La orden {$serviceOrder->code} quedó sin toros activos: agregue uno o ciérrela."
                : null,
        ];
    }
}
