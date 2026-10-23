<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Application\DTOs\ServiceOrders\StartBatchServiceOrderDTO;
use App\Application\Services\Insemination\PlannedSemenGuard;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\BatchWeightService;
use App\Core\Services\BullServiceFitnessPolicy;
use App\Core\Services\ServiceOrderCodeGenerator;
use App\Core\Services\ServiceStartPolicy;
use App\Models\Caravan;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderFemale;
use App\Models\ServiceOrderHistory;
use App\Models\ServiceOrderMale;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Puts an operational Cría batch into service.
 *
 * The service is an order over the batch, not a batch of its own: the females stay where they
 * are, the bulls join them, and the order keeps the policies (type, bull ratio, window), the
 * exposure window of every female and the trace of the campaign.
 *
 * An AI service (semen S-B) starts the same way without bulls: its females are picked one by one,
 * each with the semen planned for her, and no animal moves (IA1-IA4).
 */
final class StartBatchServiceOrderUseCase
{
    public function __construct(
        private readonly IBatchRepository $batchRepository,
        private readonly IServiceOrderRepository $serviceOrderRepository,
        private readonly ICaravanRepository $caravanRepository,
        private readonly ServiceStartPolicy $startPolicy,
        private readonly ServiceOrderCodeGenerator $codeGenerator,
        private readonly BullServiceFitnessPolicy $fitnessPolicy,
        private readonly BatchWeightService $batchWeightService,
        private readonly PlannedSemenGuard $plannedSemenGuard
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(StartBatchServiceOrderDTO $dto): ServiceOrderEntity
    {
        // 1. The batch: own, Cría, without another active service.
        $batch = $this->batchRepository->findById($dto->batchId);
        if ($batch === null) {
            throw ServiceOrderDomainException::domainError("El lote con ID {$dto->batchId} no existe.");
        }

        $this->startPolicy->assertBatchCanStartService(
            $batch,
            $this->serviceOrderRepository->findActiveServiceOnBatch($dto->batchId) !== null
        );

        // 2. Exposed females: the whole batch in natural service (the owner's breeding exclusion
        // cannot keep a cow away from bulls sharing her paddock), only eligible ones in a
        // controlled service / AI.
        $individually = $dto->picksFemalesIndividually();
        $femaleIds = $this->startPolicy->resolveExposedFemales(
            $individually,
            $this->caravanRepository->findServiceableFemaleIdsInBatch($dto->batchId, $individually),
            $dto->selectedFemaleCaravanIds,
            $this->caravanRepository->findBreedingExcludedFemaleIdsInBatch($dto->batchId)
        );
        $plannedSemen = $this->plannedSemenGuard->check($dto->plannedSemen, $femaleIds, $dto->companyId);
        $isInsemination = !$dto->serviceMethod->needsBulls();

        // 3. Bulls: males that pass the admission guard (owner decision + veterinary verdict). An AI
        // service needs none; its repaso bulls join later (IA10).
        $maleCaravanIdsToStart = $isInsemination ? [] : $dto->maleCaravanIds;
        if (!$isInsemination) {
            $this->startPolicy->assertHasBulls($dto->maleCaravanIds);
        }
        $maleCaravans = Caravan::withoutGlobalScopes()
            ->where('company_id', $dto->companyId)
            ->whereIn('id', $maleCaravanIdsToStart)
            ->with('bullHealthEvaluation')
            ->get()
            ->keyBy('id');

        $sanitaryStatusByBull = [];
        foreach ($maleCaravanIdsToStart as $maleId) {
            $bull = $maleCaravans->get($maleId);
            if ($bull === null) {
                throw ServiceOrderDomainException::domainError("El reproductor macho con ID {$maleId} no fue encontrado.");
            }

            $sex = $bull->sex instanceof AnimalSex ? $bull->sex->value : (string) $bull->sex;
            if ($sex !== AnimalSex::MALE->value) {
                throw ServiceOrderDomainException::invalidAnimalSex($maleId, 'male', $sex);
            }

            $sanitaryStatusByBull[$maleId] = $this->fitnessPolicy->assertFit($maleId, $dto->companyId, (string) $bull->identification);
        }

        // 4. No animal may be in another active service.
        $conflicts = $this->serviceOrderRepository->findActiveOrdersByCaravans(
            array_merge($femaleIds, $maleCaravanIdsToStart),
            $dto->companyId
        );
        if ($conflicts !== []) {
            throw ServiceOrderDomainException::activeOrderConflict('caravan', (int) reset($conflicts));
        }

        $femaleCaravans = Caravan::withoutGlobalScopes()
            ->whereIn('id', $femaleIds)
            ->with(['currentWeight', 'currentBodyCondition', 'femaleDetail'])
            ->get();

        try {
            $orderId = DB::transaction(function () use ($dto, $batch, $femaleCaravans, $maleCaravans, $sanitaryStatusByBull, $plannedSemen, $isInsemination, $maleCaravanIdsToStart): int {
                $orderCode = $this->codeGenerator->next($dto->companyId, $dto->plannedStartDate);

                $order = ServiceOrder::create([
                    'company_id'            => $dto->companyId,
                    'batch_id'              => $batch->getId(),
                    'parent_service_order_id' => $dto->parentServiceOrderId,
                    'code'                  => $orderCode,
                    'status'                => ServiceOrderStatus::APPROVED->value,
                    'service_type'          => $dto->serviceType,
                    'service_method'        => $dto->serviceMethod->value,
                    'is_controlled_service' => $dto->isControlledService,
                    'target_bull_ratio'     => $dto->targetBullRatio,
                    'planned_start_date'    => $dto->plannedStartDate,
                    'actual_start_date'     => $dto->plannedStartDate,
                    'planned_end_date'      => $dto->plannedEndDate,
                    'requested_by_user_id'  => $dto->userId,
                    'approved_by_user_id'   => $dto->userId,
                    'approved_at'           => now(),
                    'executed_at'           => now(),
                    'observations'          => $dto->observations,
                ]);

                $sireByFemale = [];
                if ($dto->isControlledService) {
                    foreach ($dto->femaleSireAssignments as $assignment) {
                        $sireByFemale[(int) ($assignment['female_caravan_id'] ?? 0)] = (int) ($assignment['assigned_male_caravan_id'] ?? 0);
                    }
                }

                foreach ($femaleCaravans as $female) {
                    ServiceOrderFemale::create([
                        'company_id'               => $dto->companyId,
                        'service_order_id'         => $order->id,
                        'female_caravan_id'        => $female->id,
                        'assigned_male_caravan_id' => $sireByFemale[(int) $female->id] ?? null,
                        'planned_semen_item_id'    => $plannedSemen[(int) $female->id] ?? null,
                        'snapshot_entry_weight'    => $female->currentWeight?->weight ?? $female->entry_weight,
                        'snapshot_body_condition'  => $female->currentBodyCondition?->score ?? $female->femaleDetail?->body_condition,
                        'reproductive_status'      => 'UNCHECKED',
                        'exposed_from'             => $dto->plannedStartDate,
                    ]);
                }

                foreach ($maleCaravans as $bull) {
                    ServiceOrderMale::create([
                        'company_id'                     => $dto->companyId,
                        'service_order_id'               => $order->id,
                        'male_caravan_id'                => $bull->id,
                        'joined_at'                      => $dto->plannedStartDate,
                        'snapshot_scrotal_circumference' => $bull->bullHealthEvaluation?->scrotal_circumference_cm,
                        'sanitary_status_at_entry'       => $sanitaryStatusByBull[(int) $bull->id]?->value,
                        'service_capacity'               => 'HIGH',
                        'status'                         => 'ACTIVE',
                    ]);
                }

                // The bulls join the females in the batch; both sides of the move are recomputed.
                if ($maleCaravanIdsToStart !== []) {
                    $donorBatchIds = $this->serviceOrderRepository->moveBullsToBatch(
                        $maleCaravanIdsToStart,
                        (int) $batch->getId(),
                        $dto->companyId,
                        $dto->plannedStartDate,
                        $orderCode
                    );
                    foreach ($donorBatchIds as $donorBatchId) {
                        $this->batchWeightService->recalculateBatchWeight($donorBatchId, BatchWeightCause::MOVEMENT_OUT);
                    }
                    $this->batchWeightService->recalculateBatchWeight((int) $batch->getId(), BatchWeightCause::MOVEMENT_IN);
                }

                ServiceOrderHistory::create([
                    'company_id'       => $dto->companyId,
                    'service_order_id' => $order->id,
                    'action_user_id'   => $dto->userId,
                    'from_status'      => ServiceOrderStatus::DRAFT->value,
                    'to_status'        => ServiceOrderStatus::APPROVED->value,
                    'action_reason'    => $isInsemination
                        ? "Servicio de IA iniciado sobre el lote '{$batch->getName()}'"
                        : "Servicio iniciado sobre el lote '{$batch->getName()}'",
                ]);

                return (int) $order->id;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Any other unique violation is a real failure and must not pass for this one.
            if (!self::isOneActivePerBatchViolation($e)) {
                throw $e;
            }

            // Two requests raced past the domain check: the one-active-service-per-batch index won.
            throw ServiceOrderDomainException::domainError(
                "El lote '{$batch->getName()}' ya tiene un servicio activo en curso."
            );
        }

        $order = $this->serviceOrderRepository->findById($orderId, $dto->companyId);
        if ($order === null) {
            throw ServiceOrderDomainException::domainError('Error al recuperar la orden de servicio creada.');
        }

        return $order;
    }

    /**
     * The "one active service per batch" guard: named in MySQL (index over the generated
     * `active_batch_guard` column), reported by column in SQLite (partial index over `batch_id`).
     */
    private static function isOneActivePerBatchViolation(UniqueConstraintViolationException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'uq_so_one_active_per_batch')
            || str_contains($message, 'active_batch_guard')
            || str_contains($message, 'service_orders.batch_id');
    }
}
