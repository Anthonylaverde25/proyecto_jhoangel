<?php

declare(strict_types=1);

namespace App\Application\UseCases\Activities;

use App\Core\Interfaces\IActivityRepository;

class ListAvailableActivitiesUseCase
{
    public function __construct(
        private IActivityRepository $activityRepository
    ) {}

    public function __invoke(?int $companyId = null): array
    {
        return $this->activityRepository->findAll($companyId && $companyId > 0 ? $companyId : null);
    }
}
