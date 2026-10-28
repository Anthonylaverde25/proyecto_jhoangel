<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Core\Entities\ExitOrderEntity;
use App\Core\Interfaces\IExitOrderRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** The exit order the request names, only within the active company: any other is a 404. */
final class ExitOrderOfCompany
{
    public function __construct(private readonly IExitOrderRepository $orders)
    {
    }

    public function __invoke(int $id, int $companyId): ExitOrderEntity
    {
        return $this->orders->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Exit order {$id} not found.");
    }
}
