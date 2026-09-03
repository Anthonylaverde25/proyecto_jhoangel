<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Application\DTOs\Batches\AssignExternalCaravansToOwnBatchDTO;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IFarmRepository;
use App\Core\Services\BatchWeightService;
use App\Core\ValueObjects\CaravanProvenance;
use App\Models\CaravanMovement;
use Illuminate\Support\Facades\DB;

final class AssignExternalCaravansToOwnBatchUseCase
{
    public function __construct(
        private readonly IBatchRepository $batchRepository,
        private readonly ICaravanRepository $caravanRepository,
        private readonly IFarmRepository $farmRepository,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    /**
     * @return array{transferred: int, target_batch_id: int}
     * @throws DomainException
     */
    public function __invoke(AssignExternalCaravansToOwnBatchDTO $dto): array
    {
        if (empty($dto->caravanIds)) {
            throw new DomainException("Debe seleccionar al menos una caravana para transferir.");
        }

        // 1. Validar Lote Propio Destino
        $targetBatch = $this->batchRepository->findById($dto->targetBatchId);
        if (!$targetBatch) {
            throw new DomainException("El lote propio destino especificado no existe.");
        }

        if ($targetBatch->isExternal()) {
            throw new DomainException("El lote destino seleccionado es un lote externo. Debe seleccionar un lote propio.");
        }

        if (!$targetBatch->isActive()) {
            throw new DomainException("El lote propio destino no se encuentra activo.");
        }

        $targetFarm = $targetBatch->getFarmId() ? $this->farmRepository->findById($targetBatch->getFarmId()) : null;
        $targetRenspa = $targetFarm?->getRenspa() ?? 'NO_DEFINIDO';

        $affectedSourceBatchIds = [];
        $transferredCount = 0;

        DB::transaction(function () use ($dto, $targetBatch, $targetRenspa, &$affectedSourceBatchIds, &$transferredCount) {
            foreach ($dto->caravanIds as $caravanId) {
                $caravan = $this->caravanRepository->findById($caravanId);
                if (!$caravan) {
                    continue;
                }

                $sourceBatchId = $caravan->getBatchId();
                if ($sourceBatchId !== null) {
                    $affectedSourceBatchIds[$sourceBatchId] = true;
                }

                $sourceBatch = $sourceBatchId ? $this->batchRepository->findById($sourceBatchId) : null;
                if ($sourceBatch && $sourceBatch->isOwn()) {
                    throw new DomainException("La caravana con identificación {$caravan->getIdentification()->getValue()} ya pertenece a un lote propio.");
                }
                $sourceBatchName = $sourceBatch?->getName() ?? 'Lote Externo';
                $vendorProviderId = $sourceBatch?->getProviderId() ?? $caravan->getProviderId();

                // Resolver RENSPA de procedencia histórico
                $originRenspa = $caravan->getRenspa() !== 'NO_DEFINIDO' ? $caravan->getRenspa() : null;
                if (!$originRenspa && $sourceBatch?->getFarmId()) {
                    $sourceFarm = $this->farmRepository->findById($sourceBatch->getFarmId());
                    if ($sourceFarm && $sourceFarm->getRenspa()) {
                        $originRenspa = $sourceFarm->getRenspa();
                    }
                }

                // Construir Metadatos de Procedencia Inmutables
                $currentProvenance = $caravan->getProvenance();
                $newProvenance = new CaravanProvenance(
                    originRenspa: $currentProvenance?->originRenspa ?? $originRenspa,
                    originBatchName: $currentProvenance?->originBatchName ?? $sourceBatchName,
                    originProviderId: $currentProvenance?->originProviderId ?? $vendorProviderId,
                    dteNumber: $currentProvenance?->dteNumber,
                    auctionName: $currentProvenance?->auctionName,
                    purchaseWeight: $currentProvenance?->purchaseWeight ?? $caravan->getEntryWeight(),
                    purchasePricePerKg: $currentProvenance?->purchasePricePerKg,
                    assignedToOwnBatchAt: now()->toDateTimeString(),
                    extraData: $currentProvenance?->extraData ?? []
                );

                // Asignar al Lote Propio y actualizar el contexto operativo
                $caravan->setBatchId($targetBatch->getId());
                $caravan->setRenspa($targetRenspa);
                $caravan->setProvenance($newProvenance);

                $this->caravanRepository->save($caravan);

                // Registrar Historial Inmutable de Movimiento
                CaravanMovement::create([
                    'caravan_id' => $caravan->getId(),
                    'company_id' => $dto->companyId,
                    'renspa' => $targetRenspa,
                    'type' => 'PURCHASE',
                    'movement_date' => $dto->entryDate ?? now()->toDateTimeString(),
                    'from_batch_id' => $sourceBatchId,
                    'to_batch_id' => $targetBatch->getId(),
                    'provider_id' => $vendorProviderId,
                    'from_renspa' => $originRenspa ?? 'NO_DEFINIDO',
                    'provenance_metadata' => $newProvenance->toArray(),
                    'observations' => $dto->observations ?? "Asignación desde lote externo: '{$sourceBatchName}' hacia lote propio: '{$targetBatch->getName()}'"
                ]);

                $transferredCount++;
            }

            // Recalcular pesos de lotes afectados
            $this->batchWeightService->recalculateBatchWeight($targetBatch->getId());
            foreach (array_keys($affectedSourceBatchIds) as $oldBatchId) {
                $this->batchWeightService->recalculateBatchWeight((int) $oldBatchId);
            }
        });

        return [
            'transferred' => $transferredCount,
            'target_batch_id' => $targetBatch->getId()
        ];
    }
}
