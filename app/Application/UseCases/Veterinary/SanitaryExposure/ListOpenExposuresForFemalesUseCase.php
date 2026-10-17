<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary\SanitaryExposure;

use App\Core\Interfaces\ISanitaryExposureRepository;

/**
 * Open exposure alerts of the given females, for the pregnancy check grid.
 */
final class ListOpenExposuresForFemalesUseCase
{
    public function __construct(private readonly ISanitaryExposureRepository $repository)
    {
    }

    /**
     * @param int[] $femaleIds
     * @return array<int, list<array{alert_id: int, bull_tag: string, pathogen: string, reviewed: bool}>>
     */
    public function __invoke(array $femaleIds, int $companyId): array
    {
        return $this->repository->openExposuresForFemales($femaleIds, $companyId);
    }
}
