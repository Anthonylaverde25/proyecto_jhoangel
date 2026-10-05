<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Entities\EntryOrderEntity;
use App\Core\Interfaces\IEntryOrderRepository;

final class ListEntryOrdersUseCase
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    /**
     * @return EntryOrderEntity[]
     */
    public function __invoke(int $companyId, ?string $status = null, ?int $providerId = null, bool $withOpenIncidents = false): array
    {
        return $this->repository->list($companyId, $status, $providerId, $withOpenIncidents);
    }
}
