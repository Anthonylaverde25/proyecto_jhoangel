<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanRepository;

/**
 * The calves of a batch still waiting for their tag: what "Caravanear crías" lists.
 */
final class ListUntaggedCalvesUseCase
{
    public function __construct(
        private readonly IBatchRepository $batchRepository,
        private readonly ICaravanRepository $caravanRepository
    ) {
    }

    /**
     * @return list<array{id: int, label: string, sex: string, birth_date: string, birth_weight: ?float, mother_id: int, mother_identification: string}>
     *
     * @throws DomainException
     */
    public function __invoke(int $batchId): array
    {
        // Scoped to the active company: a batch of another one is not found.
        if ($this->batchRepository->findById($batchId) === null) {
            throw new DomainException('El lote no existe.');
        }

        return $this->caravanRepository->findUntaggedCalvesByBatch($batchId);
    }
}
