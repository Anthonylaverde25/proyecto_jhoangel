<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary\SanitaryExposure;

use App\Core\Entities\SanitaryExposureAlertEntity;
use App\Core\Interfaces\ISanitaryExposureRepository;

final class ListSanitaryExposureAlertsUseCase
{
    public function __construct(private readonly ISanitaryExposureRepository $repository)
    {
    }

    /**
     * @return list<SanitaryExposureAlertEntity> Open first, most recent finding first.
     */
    public function __invoke(int $companyId, ?string $status = null): array
    {
        return $this->repository->list($companyId, $status);
    }
}
