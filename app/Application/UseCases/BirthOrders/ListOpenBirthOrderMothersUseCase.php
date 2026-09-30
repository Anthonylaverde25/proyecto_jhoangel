<?php

declare(strict_types=1);

namespace App\Application\UseCases\BirthOrders;

use App\Core\Interfaces\IBirthOrderRepository;

/**
 * The females an open birth order holds, so the screen that starts a new order does not offer them.
 */
final class ListOpenBirthOrderMothersUseCase
{
    public function __construct(private readonly IBirthOrderRepository $repository)
    {
    }

    /**
     * @return array<int, string> caravan id => order code
     */
    public function __invoke(int $companyId): array
    {
        return $this->repository->openMothers($companyId);
    }
}
