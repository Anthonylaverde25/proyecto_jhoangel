<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Interfaces\IEntryOrderRepository;

/**
 * The number the next order would get, only to preview an automatic batch name. The real number is
 * assigned inside the transaction that creates the order, so two people creating at the same time
 * may see the same preview and still get different numbers.
 */
final class PeekNextEntryOrderNumberUseCase
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    public function __invoke(int $companyId): int
    {
        return $this->repository->lastNumber($companyId) + 1;
    }
}
