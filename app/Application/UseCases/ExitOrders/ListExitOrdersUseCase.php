<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Core\Entities\ExitOrderEntity;
use App\Core\Interfaces\IExitOrderRepository;
use App\Core\ValueObjects\ExitOrderCriteria;

final class ListExitOrdersUseCase
{
    public function __construct(private readonly IExitOrderRepository $orders)
    {
    }

    /** @return list<ExitOrderEntity> */
    public function __invoke(ExitOrderCriteria $criteria): array
    {
        return $this->orders->list($criteria);
    }
}
