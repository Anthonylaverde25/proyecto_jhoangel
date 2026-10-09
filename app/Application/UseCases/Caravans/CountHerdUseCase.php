<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Interfaces\ICaravanRepository;

/**
 * How many head make up the active company's herd, and their demographic breakdown
 * for the phone's home screen.
 */
final class CountHerdUseCase
{
    public function __construct(
        private readonly ICaravanRepository $repository
    ) {
    }

    /**
     * @return array{
     *     total: int,
     *     by_sex: array{
     *         females: array{count: int, percentage: float, categories: array<int, array<string, mixed>>},
     *         males: array{count: int, percentage: float, categories: array<int, array<string, mixed>>}
     *     }
     * }
     */
    public function __invoke(): array
    {
        return $this->repository->getDemographicsBreakdown();
    }
}
