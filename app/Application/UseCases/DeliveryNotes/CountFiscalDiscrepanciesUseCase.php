<?php

declare(strict_types=1);

namespace App\Application\UseCases\DeliveryNotes;

use App\Core\Interfaces\IFiscalDiscrepancyRepository;

/** How many differences with delivery notes a document has, by state, for its file. */
final class CountFiscalDiscrepanciesUseCase
{
    public function __construct(
        private readonly IFiscalDiscrepancyRepository $discrepancies,
    ) {
    }

    /** @return array{open: int, resolved: int, cancelled: int} */
    public function __invoke(int $documentId, int $companyId): array
    {
        return $this->discrepancies->countsFor($documentId, $companyId);
    }
}
