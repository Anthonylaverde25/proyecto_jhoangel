<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Core\Enums\StockIncidentStatus;
use App\Core\Interfaces\IStockIncidentRepository;

final class ListStockIncidentsUseCase
{
    public function __construct(
        private readonly IStockIncidentRepository $incidents
    ) {
    }

    /** @return list<\App\Core\Entities\StockIncidentEntity> */
    public function __invoke(int $companyId, ?StockIncidentStatus $status, ?int $itemId): array
    {
        return $this->incidents->search($companyId, $status, $itemId);
    }
}
