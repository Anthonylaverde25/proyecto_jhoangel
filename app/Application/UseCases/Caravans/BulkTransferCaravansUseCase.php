<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\BulkTransferCaravansDTO;
use App\Application\DTOs\CreateBatchDTO;
use App\Application\Services\CaravanBatchMover;
use App\Application\Services\MotherCalfSeparation;
use App\Application\UseCases\Batches\CreateBatchUseCase;
use App\Application\UseCases\Batches\GetOrCreateReserveBatchUseCase;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanRepository;
use Illuminate\Support\Facades\DB;

final class BulkTransferCaravansUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly IBatchRepository $batchRepository,
        private readonly CaravanBatchMover $mover,
        private readonly MotherCalfSeparation $separation,
        private readonly GetOrCreateReserveBatchUseCase $getOrCreateReserveBatch,
        private readonly CreateBatchUseCase $createBatch
    ) {
    }

    /**
     * @return array{transferred_count: int, target_batch_id: int, target_batch_name: string}
     */
    public function __invoke(BulkTransferCaravansDTO $dto): array
    {
        return DB::transaction(function () use ($dto) {
            // 1. Resolve target batch. Three mutually exclusive paths: an existing batch,
            //    a brand new batch created inside this very transaction (so a failure later
            //    on never leaves an orphan batch behind), or the system reserve batch.
            if ($dto->newBatch !== null) {
                $targetBatch = ($this->createBatch)(CreateBatchDTO::fromArray($dto->newBatch));
            } elseif ($dto->targetBatchId !== null) {
                $targetBatch = $this->batchRepository->findById($dto->targetBatchId);
                if ($targetBatch === null) {
                    throw new DomainException("El lote de destino especificado no existe.");
                }
            } else {
                $targetBatch = ($this->getOrCreateReserveBatch)();
            }

            $targetBatchId = $targetBatch->getId();
            $targetBatchName = $targetBatch->getName();

            // A death is a declared fact with its date and cause, not a move: it goes through
            // "Registrar muerte". A calf without its tag, or a dead animal, does not move from here.
            if ($targetBatch->getBatchTypeCode() === 'INTERNAL_DEATH') {
                throw new DomainException('Para dar de baja por muerte usá «Registrar muerte»: guarda la fecha y la causa.');
            }

            $movementDate = new \DateTime($dto->movementDate ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s'));

            $caravans = [];
            $untagged = [];
            $dead = [];
            foreach ($dto->caravanIds as $caravanId) {
                $caravan = $this->caravanRepository->findById($caravanId);
                if ($caravan === null) {
                    continue;
                }

                if (!$caravan->isTagged()) {
                    $untagged[] = $caravan->label();
                }
                if ($caravan->isDead()) {
                    $dead[] = $caravan->label();
                }

                $caravans[$caravanId] = $caravan;
            }

            if ($untagged !== []) {
                throw new DomainException('Sin caravana: ' . implode(', ', $untagged) . '. Caravanealas antes de trasladarlas.');
            }

            if ($dead !== []) {
                throw new DomainException('Registrados como muertos: ' . implode(', ', $dead) . '. No se trasladan.');
            }

            // Parting a mother from her calf at foot weans the calf, of the kind the user chose.
            $this->separation->weanSeparated(
                array_fill_keys(array_keys($caravans), MotherCalfSeparation::batch((int) $targetBatchId)),
                $movementDate->format('Y-m-d'),
                $dto->weaningType
            );

            $this->mover->move(
                $caravans,
                $targetBatch,
                $movementDate,
                'TRANSFER',
                $dto->reason ?? ('Transferido al lote: ' . $targetBatchName)
            );
            $transferredCount = count($caravans);

            return [
                'transferred_count' => $transferredCount,
                'target_batch_id' => $targetBatchId,
                'target_batch_name' => $targetBatchName,
            ];
        });
    }
}
