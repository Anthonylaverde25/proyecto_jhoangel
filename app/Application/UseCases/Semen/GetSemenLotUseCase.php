<?php

declare(strict_types=1);

namespace App\Application\UseCases\Semen;

use App\Core\Interfaces\ISemenInventoryReader;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** The traceability of a lot (plan 5.7): balances per tank, entries, movements and identifications. */
final class GetSemenLotUseCase
{
    public function __construct(
        private readonly ISemenInventoryReader $reader,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(int $id, int $companyId): array
    {
        return $this->reader->lotDetail($id, $companyId) ?? throw new ModelNotFoundException("Semen lot {$id} not found.");
    }
}
