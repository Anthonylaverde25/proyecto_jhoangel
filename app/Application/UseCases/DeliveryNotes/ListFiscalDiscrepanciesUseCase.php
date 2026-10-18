<?php

declare(strict_types=1);

namespace App\Application\UseCases\DeliveryNotes;

use App\Core\Entities\FiscalDiscrepancyEntity;
use App\Core\Interfaces\IFiscalDiscrepancyRepository;
use App\Core\ValueObjects\DiscrepancyFilter;

final class ListFiscalDiscrepanciesUseCase
{
    public function __construct(
        private readonly IFiscalDiscrepancyRepository $discrepancies,
    ) {
    }

    /** @return list<FiscalDiscrepancyEntity> */
    public function __invoke(DiscrepancyFilter $filter, int $companyId): array
    {
        return $this->discrepancies->search($filter, $companyId);
    }
}
