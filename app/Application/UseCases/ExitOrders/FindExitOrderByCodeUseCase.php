<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Core\Entities\ExitOrderEntity;
use App\Core\Interfaces\IExitOrderRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** An order by its printed code (EG-…), for a sheet to come. */
final class FindExitOrderByCodeUseCase
{
    public function __construct(private readonly IExitOrderRepository $orders)
    {
    }

    public function __invoke(string $code, int $companyId): ExitOrderEntity
    {
        return $this->orders->findByCode($code, $companyId)
            ?? throw new ModelNotFoundException("Exit order {$code} not found.");
    }
}
