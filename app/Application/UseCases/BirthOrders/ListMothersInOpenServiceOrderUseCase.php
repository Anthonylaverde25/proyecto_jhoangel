<?php

declare(strict_types=1);

namespace App\Application\UseCases\BirthOrders;

use App\Core\Interfaces\IBirthOrderRepository;

/**
 * The pregnant females whose service order is not closed yet: the birth screens leave them out and
 * say which order has to close first.
 */
final class ListMothersInOpenServiceOrderUseCase
{
    public function __construct(private readonly IBirthOrderRepository $repository)
    {
    }

    /**
     * @return array<int, array{order_id: int, code: string}> caravan id => the service order
     */
    public function __invoke(int $companyId): array
    {
        return $this->repository->mothersInOpenServiceOrder(null, $companyId);
    }
}
