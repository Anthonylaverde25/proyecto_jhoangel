<?php

declare(strict_types=1);

namespace App\Application\UseCases\PreService;

use App\Core\Entities\PathogenEntity;
use App\Core\Interfaces\IPathogenRepository;

final class ListPathogensUseCase
{
    public function __construct(
        private readonly IPathogenRepository $pathogenRepository
    ) {
    }

    /**
     * @return array<PathogenEntity>
     */
    public function __invoke(): array
    {
        return $this->pathogenRepository->findAll();
    }
}
