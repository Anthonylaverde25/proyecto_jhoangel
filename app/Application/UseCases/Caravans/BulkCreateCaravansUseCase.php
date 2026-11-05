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
     * @return list<string> what the operator should know about these rows
     */
    public function __invoke(array $dtos): array
    {
        return DB::transaction(function () use ($dtos): array {
            $warnings = [];
            foreach ($dtos as $dto) {
                array_push($warnings, ...($this->upsertCaravanUseCase)($dto)->warnings);
            }

            return $warnings;
        });
    }
}
