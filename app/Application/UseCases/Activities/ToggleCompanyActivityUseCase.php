<?php

declare(strict_types=1);

namespace App\Application\UseCases\Activities;

use App\Core\Interfaces\IActivityRepository;

class ToggleCompanyActivityUseCase
{
    public function __construct(
        private IActivityRepository $activityRepository
    ) {}

    public function __invoke(int $companyId, int $activityId, bool $isEnabled): bool
    {
        return $this->activityRepository.toggleActivity($companyId, $activityId, $isEnabled);
    }
}
