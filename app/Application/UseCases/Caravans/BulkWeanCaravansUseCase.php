<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\BulkWeanDTO;
use Illuminate\Support\Facades\DB;

final class BulkWeanCaravansUseCase
{
    public function __construct(
        private readonly WeanCaravanUseCase $weanCaravanUseCase
    ) {
    }

    public function __invoke(BulkWeanDTO $dto): void
    {
        DB::transaction(function () use ($dto) {
            foreach ($dto->weanings as $weaningDto) {
                ($this->weanCaravanUseCase)($weaningDto);
            }
        });
    }
}
