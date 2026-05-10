<?php

declare(strict_types=1);

namespace App\Application\UseCases\Activities;

use App\Core\Interfaces\IActivityRepository;

class ListAvailableActivitiesUseCase
{
    public function __construct(
        private IActivityRepository $activityRepository
    ) {}

    public function __invoke(int $companyId): array
    {
        // This could be improved to return the catalog with the current status for the company
        return $this->activityRepository.findAll();
    }
}
