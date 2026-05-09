<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\RegisterCaravanDTO;
use Illuminate\Support\Facades\DB;

final class BulkCreateCaravansUseCase
{
    public function __construct(
        private readonly UpsertCaravanUseCase $upsertCaravanUseCase
    ) {
    }

    /**
     * @param RegisterCaravanDTO[] $dtos
     */
    public function __invoke(array $dtos): void
    {
        DB::transaction(function () use ($dtos) {
            foreach ($dtos as $dto) {
                ($this->upsertCaravanUseCase)($dto);
            }
        });
    }
}
