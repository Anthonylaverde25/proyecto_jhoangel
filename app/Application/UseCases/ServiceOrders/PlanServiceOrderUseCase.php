<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\ServiceOrders\IssueServiceOrdersDTO;
use App\Application\DTOs\ServiceOrders\ServiceOrderLotDTO;
use App\Application\Services\Insemination\PlannedSemenGuard;
use App\Application\Services\PendingTactoFemales;
use App\Application\Services\PlannedBullsReview;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\ServiceOrderCodeGenerator;
use App\Core\Services\ServiceStartPolicy;

/**
 * A service organized ahead of time. It moves no animal and takes neither the batch nor the animals:
 * what would stop it today is only a warning, because it may no longer be true on the day. Starting
 * the order applies every rule again.
 */
final class PlanServiceOrderUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $orders,
        private readonly IBatchRepository $batches,
        private readonly ICaravanRepository $caravans,
        private readonly ServiceStartPolicy $startPolicy,
        private readonly ServiceOrderCodeGenerator $codeGenerator,
        private readonly PlannedSemenGuard $plannedSemenGuard,
        private readonly PlannedBullsReview $bullsReview,
        private readonly PendingTactoFemales $pendingTacto
    ) {
    }

    /**
     * @return array{order: ServiceOrderEntity, warnings: list<string>}
     *
     * @throws ServiceOrderDomainException
     */
    public function __invoke(IssueServiceOrdersDTO $dto, ServiceOrderLotDTO $lot): array
    {
        $batch = $this->batches->findById($lot->batchId)
            ?? throw ServiceOrderDomainException::domainError("El lote con ID {$lot->batchId} no existe.");
        $this->startPolicy->assertBatchCanStartService($batch, null);

        $warnings = [];
        $unclosed = $this->orders->findUnclosedOrderOnBatch($lot->batchId);
        if ($unclosed !== null) {
            $warnings[] = "{$batch->getName()} tiene hoy la orden {$unclosed['code']} sin cerrar: hay que cerrarla antes de iniciar esta.";
        }

        $individually = $lot->isControlledService || $dto->serviceMethod->picksFemalesIndividually();
        $serviceable = $this->caravans->findServiceableFemaleIdsInBatch($lot->batchId, $individually);
        if ($individually) {
            $femaleIds = $this->startPolicy->resolveExposedFemales(true, $serviceable, $lot->selectedFemaleCaravanIds, $this->caravans->findBreedingExcludedFemaleIdsInBatch($lot->batchId));
        } else {
            // The females of a natural service are the empty ones on the day it starts.
            $femaleIds = $serviceable;
            if ($femaleIds === []) {
                $warnings[] = "{$batch->getName()} no tiene hoy vientres vacíos: se toman los que estén vacíos al iniciar.";
            }
        }
        $pending = $this->pendingTacto->of($femaleIds, $dto->companyId);
        if ($pending !== []) {
            $warnings[] = "{$batch->getName()} tiene vientres con el tacto pendiente en otra orden: {$this->pendingTacto->describe($pending)}. Tactalos antes de iniciar esta.";
        }
        $plannedSemen = $this->plannedSemenGuard->check($lot->plannedSemen, $femaleIds, $dto->companyId);

        $maleIds = $dto->serviceMethod->needsBulls() ? $lot->maleCaravanIds : [];
        if ($dto->serviceMethod->needsBulls()) {
            $this->startPolicy->assertHasBulls($maleIds);
        }
        array_push($warnings, ...$this->bullsReview->warnings($maleIds, $dto->companyId));

        $orderId = $this->orders->createPlanned(
            [
                'company_id'            => $dto->companyId,
                'batch_id'              => $lot->batchId,
                'code'                  => $this->codeGenerator->next($dto->companyId, $dto->startDate),
                'service_type'          => $lot->serviceType,
                'service_method'        => $dto->serviceMethod->value,
                'is_controlled_service' => $lot->isControlledService,
                'planned_start_date'    => $dto->startDate,
                'planned_end_date'      => $dto->endDate,
                'requested_by_user_id'  => $dto->userId,
                'observations'          => $dto->observations,
            ],
            $femaleIds,
            $maleIds,
            $plannedSemen,
            "Servicio planificado sobre el lote '{$batch->getName()}' para el {$dto->startDate}"
        );

        $order = $this->orders->findById($orderId, $dto->companyId)
            ?? throw ServiceOrderDomainException::domainError('Error al recuperar la orden planificada.');

        return ['order' => $order, 'warnings' => $warnings];
    }
}
