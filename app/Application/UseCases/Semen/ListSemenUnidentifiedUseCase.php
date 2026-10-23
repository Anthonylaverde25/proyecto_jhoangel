<?php

declare(strict_types=1);

namespace App\Application\UseCases\Semen;

use App\Core\Interfaces\ISemenUnidentifiedRepository;

/** Straws still without lot (SU2), with the operation they came from. */
final class ListSemenUnidentifiedUseCase
{
    public function __construct(
        private readonly ISemenUnidentifiedRepository $unidentified,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(int $companyId, ?int $itemId, ?int $warehouseId): array
    {
        return $this->unidentified->listOpen($companyId, $itemId, $warehouseId);
    }
}
