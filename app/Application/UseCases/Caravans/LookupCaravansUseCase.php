<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Application\DTOs\CaravanLookupResultDTO;
use App\Core\Enums\CaravanLookupStatus;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICompanyContext;

/**
 * Classifies tags read at the chute against everything the tenant already holds.
 *
 * Shared by the strict registration (which refuses anything found) and, later, by the
 * identification of existing animals (which wants exactly the ones found).
 */
final class LookupCaravansUseCase
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICompanyContext $companyContext,
    ) {
    }

    /**
     * @param string[] $identifications
     * @return CaravanLookupResultDTO[] one per distinct identification, in input order
     */
    public function __invoke(array $identifications): array
    {
        $distinct = array_values(array_unique(array_map(
            static fn ($raw): string => trim((string) $raw),
            $identifications
        )));

        $ownership = $this->caravanRepository->findOwnershipByIdentifications($distinct);
        $activeCompanyId = $this->companyContext->getCompanyId();

        return array_map(function (string $identification) use ($ownership, $activeCompanyId): CaravanLookupResultDTO {
            $holder = $ownership[$identification] ?? null;

            if ($holder === null) {
                return new CaravanLookupResultDTO($identification, CaravanLookupStatus::NOT_FOUND);
            }

            if ($holder->companyId === $activeCompanyId) {
                return new CaravanLookupResultDTO(
                    $identification,
                    CaravanLookupStatus::OWN_COMPANY,
                    $holder->caravanId,
                    $holder->batchName
                );
            }

            return new CaravanLookupResultDTO($identification, CaravanLookupStatus::OTHER_COMPANY);
        }, $distinct);
    }
}
