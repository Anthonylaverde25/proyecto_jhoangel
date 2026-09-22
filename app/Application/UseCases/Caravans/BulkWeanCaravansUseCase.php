<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\BulkWeanDTO;
use App\Application\DTOs\WeanCaravanDTO;
use App\Application\UseCases\Batches\CreateBatchUseCase;
use App\Core\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

final class BulkWeanCaravansUseCase
{
    public function __construct(
        private readonly WeanCaravanUseCase $weanCaravanUseCase,
        private readonly CreateBatchUseCase $createBatchUseCase
    ) {
    }

    public function __invoke(BulkWeanDTO $dto): void
    {
        DB::transaction(function () use ($dto) {
            $targetBatchId = null;

            if ($dto->newBatch !== null) {
                $batchEntity = ($this->createBatchUseCase)($dto->newBatch);
                $targetBatchId = $batchEntity->getId();
            }

            foreach ($dto->weanings as $weaningDto) {
                $effectiveBatchId = $weaningDto->targetBatchId > 0 ? $weaningDto->targetBatchId : $targetBatchId;
                if (!$effectiveBatchId) {
                    throw new DomainException("No se especificó un lote de destino válido para el destete.");
                }

                $effectiveWeaningDto = new WeanCaravanDTO(
                    caravanId: $weaningDto->caravanId,
                    targetBatchId: $effectiveBatchId,
                    weaningDate: $weaningDto->weaningDate,
                    weaningWeight: $weaningDto->weaningWeight,
                    newCategory: $weaningDto->newCategory,
                    notes: $weaningDto->notes,
                    newCategoryId: $weaningDto->newCategoryId,
                    newSubcategoryId: $weaningDto->newSubcategoryId
                );

                ($this->weanCaravanUseCase)($effectiveWeaningDto);
            }
        });
    }
}
