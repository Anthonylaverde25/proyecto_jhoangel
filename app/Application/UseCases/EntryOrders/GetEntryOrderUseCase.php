<?php

declare(strict_types=1);

namespace App\Application\UseCases\EntryOrders;

use App\Core\Entities\EntryOrderEntity;
use App\Core\Interfaces\IEntryOrderRepository;

final class GetEntryOrderUseCase
{
    public function __construct(private readonly IEntryOrderRepository $repository)
    {
    }

    public function __invoke(int $id, int $companyId): ?EntryOrderEntity
    {
        return $this->repository->findById($id, $companyId);
    }
}
