<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IBatchOverviewRepository;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Services\CategoryWeightSeries;

final class GetBatchCategoryWeightSeriesUseCase
{
    public function __construct(
        private readonly IBatchRepository $batches,
        private readonly IBatchOverviewRepository $overview,
        private readonly CategoryWeightSeries $series,
    ) {
    }

    /**
     * @return list<array{weighing_date: string, category_id: ?int, category_name: ?string, average_weight: float, weighed_count: int, head_count: int}>
     */
    public function __invoke(int $batchId): array
    {
        if ($this->batches->findById($batchId) === null) {
            throw new DomainException('Lote no encontrado.');
        }

        $heads = [];
        foreach ($this->overview->categoryComposition($batchId) as $category) {
            $heads[CategoryWeightSeries::key($category['category_id'])] = $category['count'];
        }

        return $this->series->build($this->overview->categoryWeightReadings($batchId), $heads);
    }
}
