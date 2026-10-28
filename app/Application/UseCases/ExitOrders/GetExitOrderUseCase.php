<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExitOrders;

use App\Core\Entities\ExitOrderEntity;

final class GetExitOrderUseCase
{
    public function __construct(private readonly ExitOrderOfCompany $orderOfCompany)
    {
    }

    public function __invoke(int $id, int $companyId): ExitOrderEntity
    {
        return ($this->orderOfCompany)($id, $companyId);
    }
}
