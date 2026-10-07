<?php

declare(strict_types=1);

namespace App\Application\UseCases\Caravans;

use App\Core\Interfaces\ICaravanRepository;

/**
 * How many head make up the active company's herd, for the phone's home screen.
 */
final class CountHerdUseCase
{
    public function __construct(
        private readonly ICaravanRepository $repository
    ) {
    }

    public function __invoke(): int
    {
        return $this->repository->countOwn();
    }
}
