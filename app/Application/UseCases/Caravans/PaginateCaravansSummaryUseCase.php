<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Interfaces\ICaravanRepository;
use App\Core\ValueObjects\CaravanSummaryCriteria;
use App\Core\ValueObjects\CaravanSummaryRow;
use App\Core\ValueObjects\PaginatedResult;

final class PaginateCaravansSummaryUseCase
{
    public function __construct(
        private readonly ICaravanRepository $repository
    ) {
    }

    /**
     * @return PaginatedResult<CaravanSummaryRow>
     */
    public function __invoke(CaravanSummaryCriteria $criteria): PaginatedResult
    {
        return $this->repository->paginateSummary($criteria);
    }
}
