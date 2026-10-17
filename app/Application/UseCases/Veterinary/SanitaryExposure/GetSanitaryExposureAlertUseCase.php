<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary\SanitaryExposure;

use App\Core\Entities\SanitaryExposureAlertEntity;
use App\Core\Interfaces\ISanitaryExposureRepository;

final class GetSanitaryExposureAlertUseCase
{
    public function __construct(private readonly ISanitaryExposureRepository $repository)
    {
    }

    public function __invoke(int $alertId, int $companyId): ?SanitaryExposureAlertEntity
    {
        return $this->repository->find($alertId, $companyId);
    }
}
