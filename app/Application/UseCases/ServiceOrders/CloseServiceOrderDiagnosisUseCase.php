<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\ServiceOrders\CloseServiceOrderDiagnosisDTO;
use App\Application\DTOs\ServiceOrders\MarkFemalesNotDiagnosedDTO;
use App\Application\DTOs\ServiceOrders\StartBatchServiceOrderDTO;
use App\Application\DTOs\TransferOrders\EmitTransferOrderDTO;
use App\Application\Services\CaravanBatchRelocation;
use App\Application\Services\ServiceBullWithdrawal;
use App\Application\UseCases\Batches\StartBatchServiceOrderUseCase;
use App\Application\UseCases\Caravans\RegisterGestationDiagnosisUseCase;
use App\Application\UseCases\TransferOrders\CreateTransferOrderUseCase;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Entities\TransferOrderEntity;
use App\Core\Enums\FemaleReproductiveVerdict;
use App\Core\Enums\ServiceClosureBullAction;
use App\Core\Enums\ServiceClosureRemainingFemalesAction;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * Closes a service order by its pregnancy diagnosis ("Cerrar orden").
 *
 * 1. The verdicts still missing are registered (screen) — the sheet path registers them before.
 * 2. Every female must have a final verdict as of the closing day: an empty scanned while still
 *    exposed is not final. Otherwise nothing is kept (one transaction) and the pending are named.
 * 3. Bulls still in the batch (an order closed in service): they leave now, leave on a scheduled
 *    day (issued transfer order) or stay. When they do not leave now, the non-pregnant females
 *    that would stay with them either leave the batch now or go on in a continuation order:
 *    otherwise they would be exposed with no order recording it.
 * 4. The order is closed (SUCCESS) and its pregnancy rate fixed.
 */
final class CloseServiceOrderDiagnosisUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $serviceOrderRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly RegisterGestationDiagnosisUseCase $registerDiagnosis,
        private readonly MarkFemalesNotDiagnosedUseCase $markNotDiagnosed,
        private readonly ServiceBullWithdrawal $bullWithdrawal,
        private readonly CaravanBatchRelocation $relocation,
        private readonly StartBatchServiceOrderUseCase $startServiceOrder,
        private readonly CreateTransferOrderUseCase $createTransferOrder
    ) {
    }

    /**
     * @return array{order: ServiceOrderEntity, continuation_order: ?ServiceOrderEntity, transfer_order: ?TransferOrderEntity, moved_female_ids: int[]}
     *
     * @throws ServiceOrderDomainException
     */
    public function __invoke(CloseServiceOrderDiagnosisDTO $dto): array
    {
        $order = $this->loadOrder($dto);
        if (!$order->acceptsDiagnosis()) {
            throw ServiceOrderDomainException::domainError(
                $order->getStatus() === ServiceOrderStatus::SUCCESS
                    ? "La orden {$order->getCode()} ya está cerrada."
                    : "La orden {$order->getCode()} no está en servicio ni esperando diagnóstico."
            );
        }

        $closingDate = $this->assertDay($dto->closingDate, 'cierre');
        if ($closingDate < $this->assertDay($dto->roundDate, 'la ronda')) {
            throw ServiceOrderDomainException::domainError('La fecha de cierre no puede ser anterior a la de la ronda de diagnóstico.');
        }

        try {
            return DB::transaction(fn (): array => $this->close($dto, $order, $closingDate));
        } catch (ServiceOrderDomainException $e) {
            throw $e;
        } catch (DomainException $e) {
            // Errors of the diagnosis, the continuation or the transfer order, shown as they are.
            throw ServiceOrderDomainException::domainError($e->getMessage());
        }
    }

    /**
     * @return array{order: ServiceOrderEntity, continuation_order: ?ServiceOrderEntity, transfer_order: ?TransferOrderEntity, moved_female_ids: int[]}
     *
     * @throws DomainException
     */
    private function close(CloseServiceOrderDiagnosisDTO $dto, ServiceOrderEntity $order, string $closingDate): array
    {
        $orderId = (int) $order->getId();

        // 1. Verdicts of step 1.
        foreach ($dto->diagnoses as $diagnosis) {
            ($this->registerDiagnosis)($diagnosis, $dto->companyId);
        }

        if ($dto->notDiagnosed !== []) {
            ($this->markNotDiagnosed)(new MarkFemalesNotDiagnosedDTO(
                serviceOrderId: $orderId,
                companyId: $dto->companyId,
                userId: $dto->userId,
                roundDate: $dto->roundDate,
                females: $dto->notDiagnosed,
                duringClosing: true
            ));
        }

        // 2. Every female with a final verdict as of the closing day.
        $order = $this->loadOrder($dto);
        $summary = $order->getDiagnosisSummary($closingDate);
        if ($summary->pendingCount() > 0) {
            throw ServiceOrderDomainException::pendingDiagnoses($summary->getPendingFemaleIds());
        }

        // 3. Bulls and the females that would stay with them.
        $activeBullIds = $order->getStatus() === ServiceOrderStatus::APPROVED ? $order->getActiveMaleCaravanIds() : [];
        $movedFemaleIds = [];
        $continueWith = [];
        $scheduleBulls = false;

        if ($activeBullIds !== []) {
            $bullAction = $dto->bullAction
                ?? throw ServiceOrderDomainException::domainError('Indicá qué pasa con los toros que siguen en el lote.');

            if ($bullAction === ServiceClosureBullAction::MOVE_NOW) {
                $this->bullWithdrawal->withdraw(
                    orderId: $orderId,
                    companyId: $dto->companyId,
                    serviceBatchId: $order->getBatchId(),
                    orderCode: $order->getCode(),
                    withdrawalDate: $closingDate,
                    bullDestinations: $dto->bullDestinations,
                    defaultDestinationBatchId: $dto->bullDestinationBatchId,
                    observations: $dto->observations
                );
            } else {
                [$movedFemaleIds, $continueWith] = $this->resolveRemainingFemales($dto, $order, $bullAction, $closingDate);
                $this->serviceOrderRepository->completeActiveBulls($orderId, $closingDate);
                $scheduleBulls = $bullAction === ServiceClosureBullAction::SCHEDULE;
            }
        }

        // Optional: the final empties still in the service batch leave it.
        if ($dto->emptiesTargetBatchId !== null) {
            $empties = $this->serviceOrderRepository->findNonPregnantFemalesInBatch(
                $orderId,
                $order->getBatchId(),
                [FemaleReproductiveVerdict::EMPTY->value]
            );
            $movedFemaleIds = array_merge($movedFemaleIds, $this->relocation->relocate(
                $empties,
                $dto->companyId,
                $dto->emptiesTargetBatchId,
                $closingDate,
                "Vacía confirmada al cierre de la orden {$order->getCode()}"
            ));
        }

        // 4. Close.
        $order = $this->loadOrder($dto);
        $order->closeDiagnosis($closingDate, $dto->observations !== null && trim($dto->observations) !== '' ? "Cierre: {$dto->observations}" : null);
        $closed = $this->serviceOrderRepository->save(
            $order,
            (int) $dto->userId,
            $this->closingReason($order, $activeBullIds, $dto->bullAction)
        );

        $continuation = $continueWith !== [] ? $this->startContinuation($dto, $closed, $activeBullIds, $continueWith, $closingDate) : null;
        $transfer = $scheduleBulls ? $this->scheduleBullTransfer($dto, $closed, $activeBullIds) : null;

        return [
            'order' => $closed,
            'continuation_order' => $continuation,
            'transfer_order' => $transfer,
            'moved_female_ids' => array_values(array_unique($movedFemaleIds)),
        ];
    }

    /**
     * Bulls stay (now or until a scheduled day): the non-pregnant females in the batch leave it
     * now, or go on in service under a continuation order.
     *
     * @return array{0: int[], 1: int[]} moved females, females that continue in service
     *
     * @throws ServiceOrderDomainException
     */
    private function resolveRemainingFemales(
        CloseServiceOrderDiagnosisDTO $dto,
        ServiceOrderEntity $order,
        ServiceClosureBullAction $bullAction,
        string $closingDate
    ): array {
        $remaining = $this->serviceOrderRepository->findNonPregnantFemalesInBatch((int) $order->getId(), $order->getBatchId());
        if ($remaining === []) {
            return [[], []];
        }

        $action = $dto->remainingFemalesAction
            ?? throw ServiceOrderDomainException::domainError(
                count($remaining) . ' vientre(s) no preñado(s) quedarían con los toros: sacalos del lote o seguí el servicio en una orden de continuación.'
            );

        if ($action === ServiceClosureRemainingFemalesAction::CONTINUE_SERVICE) {
            if ($bullAction === ServiceClosureBullAction::SCHEDULE) {
                throw ServiceOrderDomainException::domainError(
                    'Si el servicio continúa con estos toros, su traslado no se programa ahora: se decide al cerrar la orden de continuación.'
                );
            }

            return [[], $remaining];
        }

        $target = $dto->remainingFemalesTargetBatchId
            ?? throw ServiceOrderDomainException::domainError('Elegí el lote al que salen los vientres no preñados.');

        if ($target === $order->getBatchId()) {
            throw ServiceOrderDomainException::domainError('El lote de destino de los vientres no preñados no puede ser el lote de servicio.');
        }

        $moved = $this->relocation->relocate(
            $remaining,
            $dto->companyId,
            $target,
            $closingDate,
            "Sale del lote de servicio al cierre de la orden {$order->getCode()} (los toros permanecen)"
        );

        return [$moved, []];
    }

    /**
     * @param int[] $bullIds
     * @param int[] $femaleIds
     */
    private function startContinuation(
        CloseServiceOrderDiagnosisDTO $dto,
        ServiceOrderEntity $parent,
        array $bullIds,
        array $femaleIds,
        string $closingDate
    ): ServiceOrderEntity {
        $assignments = array_values(array_filter(
            $parent->getFemaleSireAssignments(),
            static fn (array $a): bool => in_array((int) $a['female_caravan_id'], $femaleIds, true)
                && in_array((int) $a['assigned_male_caravan_id'], $bullIds, true)
        ));

        return ($this->startServiceOrder)(new StartBatchServiceOrderDTO(
            batchId: $parent->getBatchId(),
            companyId: $dto->companyId,
            userId: (int) $dto->userId,
            maleCaravanIds: $bullIds,
            selectedFemaleCaravanIds: $femaleIds,
            targetBullRatio: $parent->getTargetBullRatio() ?? 3.0,
            plannedStartDate: $closingDate,
            plannedEndDate: $parent->getPlannedEndDate() !== null && $parent->getPlannedEndDate() > $closingDate
                ? $parent->getPlannedEndDate()
                : null,
            serviceType: $parent->getServiceType(),
            isControlledService: $parent->isControlledService(),
            femaleSireAssignments: $assignments,
            observations: "Continuación de la orden {$parent->getCode()}: los toros siguen con los vientres no preñados.",
            parentServiceOrderId: (int) $parent->getId()
        ));
    }

    /**
     * @param int[] $bullIds
     *
     * @throws DomainException
     */
    private function scheduleBullTransfer(CloseServiceOrderDiagnosisDTO $dto, ServiceOrderEntity $order, array $bullIds): TransferOrderEntity
    {
        $targetBatchId = $dto->bullDestinationBatchId
            ?? throw ServiceOrderDomainException::domainError('Elegí el lote al que se trasladarán los toros.');
        $date = $dto->scheduledMovementDate
            ?? throw ServiceOrderDomainException::domainError('Indicá la fecha programada del traslado de los toros.');
        if (substr($date, 0, 10) < (string) $order->getDiagnosisCompletedAt()) {
            throw ServiceOrderDomainException::domainError('El traslado programado de los toros no puede ser anterior al cierre de la orden.');
        }

        $target = $this->batchRepository->findById($targetBatchId)
            ?? throw ServiceOrderDomainException::domainError('El lote de destino de los toros no existe.');

        return ($this->createTransferOrder)(new EmitTransferOrderDTO(
            companyId: $dto->companyId,
            requestedByUserId: $dto->userId,
            sourceBatchId: $order->getBatchId(),
            destinationActivityId: (int) $target->getActivityId(),
            destinationMode: TransferOrderEntity::MODE_SINGLE,
            movementDate: substr($date, 0, 10),
            responsable: null,
            observations: "Retiro programado de torada al cierre de la orden de servicio {$order->getCode()}",
            destinations: [[
                'key' => 'bulls',
                'label' => $target->getName(),
                'target_batch_id' => $targetBatchId,
                'new_batch_name' => null,
                'new_batch_type_id' => null,
                'is_confined' => null,
            ]],
            animals: array_map(static fn (int $bullId): array => [
                'caravan_id' => $bullId,
                'destination_key' => 'bulls',
            ], $bullIds),
            issue: true
        ));
    }

    /**
     * @param int[] $activeBullIds
     */
    private function closingReason(ServiceOrderEntity $order, array $activeBullIds, ?ServiceClosureBullAction $bullAction): string
    {
        $summary = $order->getDiagnosisSummary($order->getDiagnosisCompletedAt());
        $rate = $order->getFinalPregnancyRate();
        $reason = sprintf(
            'Orden cerrada con su diagnóstico: %d preñadas, %d vacías%s.',
            $summary->getPregnant(),
            $summary->getEmpty(),
            $rate !== null ? sprintf(' (preñez %s%%)', number_format($rate, 1, ',', '.')) : ''
        );

        if ($activeBullIds !== [] && $bullAction !== null) {
            $reason .= ' Toros: ' . mb_strtolower($bullAction->label()) . '.';
        }

        return $reason;
    }

    /**
     * @throws ServiceOrderDomainException
     */
    private function loadOrder(CloseServiceOrderDiagnosisDTO $dto): ServiceOrderEntity
    {
        return $this->serviceOrderRepository->findById($dto->serviceOrderId, $dto->companyId)
            ?? throw ServiceOrderDomainException::domainError('La orden de servicio no existe.');
    }

    /**
     * @throws ServiceOrderDomainException
     */
    private function assertDay(string $date, string $what): string
    {
        $day = substr($date, 0, 10);
        if ($day > date('Y-m-d')) {
            throw ServiceOrderDomainException::domainError("La fecha de {$what} no puede ser futura.");
        }

        return $day;
    }
}
