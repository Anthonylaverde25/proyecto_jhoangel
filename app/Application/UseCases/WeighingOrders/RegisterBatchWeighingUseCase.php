<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeighingOrders;

use App\Application\DTOs\RegisterBatchWeighingDTO;
use App\Application\Services\WeighingOrderCodeGenerator;
use App\Core\Entities\WeighingOrderAnimalEntity;
use App\Core\Entities\WeighingOrderEntity;
use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\WeighingMethod;
use App\Core\Enums\WeighingSource;
use App\Core\Exceptions\WeighingOrderDomainException;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IWeighingOrderRepository;
use App\Core\Services\BatchWeightService;
use App\Core\Services\CaravanWeighingRecorder;
use App\Core\Services\GroupWeightImputation;
use App\Core\ValueObjects\WeighingReading;
use Illuminate\Support\Facades\DB;

/**
 * Registers a weighing of a batch after it happened, from its loads on a cage or truck scale or
 * from the average of each category; either way each category gets its own average.
 *
 * The measure stays on the order as the document, each caravan of a category weighed receives the
 * average of its category as an AVERAGE weight, and the batch is recomputed from its animals.
 */
final class RegisterBatchWeighingUseCase
{
    public function __construct(
        private readonly IBatchRepository $batches,
        private readonly ICaravanRepository $caravans,
        private readonly IWeighingOrderRepository $orders,
        private readonly WeighingOrderCodeGenerator $codes,
        private readonly GroupWeightImputation $imputation,
        private readonly CaravanWeighingRecorder $recorder,
        private readonly BatchWeightService $batchWeightService,
        private readonly IAnimalCategoryRepository $categories,
    ) {
    }

    /**
     * @throws WeighingOrderDomainException
     */
    public function __invoke(RegisterBatchWeighingDTO $dto): WeighingOrderEntity
    {
        $batch = $this->batches->findById($dto->batchId);
        if ($batch === null) {
            throw WeighingOrderDomainException::domainError('El lote no existe.', 'BATCH_NOT_FOUND');
        }

        $date = new \DateTimeImmutable($dto->weighingDate);
        if ($date > new \DateTimeImmutable('today')) {
            throw WeighingOrderDomainException::domainError('La fecha del pesaje no puede ser futura.', 'FUTURE_DATE');
        }

        return DB::transaction(function () use ($dto, $date): WeighingOrderEntity {
            $categoryNames = [];
            foreach ($this->categories->all() as $category) {
                $categoryNames[(int) $category->getId()] = $category->getName();
            }

            $weights = $this->imputation->impute(
                $dto->measure,
                $this->caravans->findCategoriesInPossessionByBatch($dto->batchId),
                $categoryNames
            );

            $order = WeighingOrderEntity::registerBatchWeighing(
                companyId: $dto->companyId,
                code: $this->codes->next($dto->companyId, new \DateTimeImmutable()),
                type: $dto->type,
                batchId: $dto->batchId,
                shrunkCondition: $dto->shrunkCondition,
                shrinkPct: $dto->shrinkPct,
                weighingDate: $dto->weighingDate,
                weighingTime: $dto->weighingTime,
                requestedByUserId: $dto->userId,
                responsable: $dto->responsable,
                observations: $dto->observations,
                measure: $dto->measure,
                animals: array_map(
                    static fn (int $id) => new WeighingOrderAnimalEntity(null, $id, $dto->batchId),
                    array_keys($weights)
                ),
            );

            $order = $this->orders->create($order, $dto->userId);
            $at = new \DateTimeImmutable();

            foreach ($weights as $caravanId => $weight) {
                $saved = $this->recorder->record($caravanId, new WeighingReading(
                    weight: $weight,
                    date: $date,
                    type: $dto->type,
                    method: WeighingMethod::AVERAGE,
                    source: WeighingSource::WEIGHING_ORDER,
                    shrunkCondition: $dto->shrunkCondition,
                    shrinkPct: $dto->shrinkPct,
                    time: $dto->weighingTime,
                    notes: "Promedio de su categoría ({$order->getCode()})",
                    weighingOrderId: $order->getId(),
                ));

                $order->animalByCaravanId($caravanId)?->markWeighed((int) $saved->getId(), $at);
            }

            $this->orders->saveAnimalLines($order);

            $this->batchWeightService->recalculateBatchWeight(
                $dto->batchId,
                BatchWeightCause::CONTROL,
                $date,
                $dto->type,
                $order->getId()
            );

            return $this->orders->findById((int) $order->getId(), $dto->companyId) ?? $order;
        });
    }
}
