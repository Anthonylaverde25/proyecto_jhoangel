<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

final class BatchUseCases
{
    public function __construct(
        public readonly ListBatchesUseCase $list,
        public readonly CreateBatchUseCase $create,
    ) {
    }
}
