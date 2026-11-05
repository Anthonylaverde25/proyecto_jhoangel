<?php

declare(strict_types=1);

namespace App\Application\UseCases\Castrations;

use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Interfaces\ICaravanCastrationRepository;

final class ListCastrationCandidatesUseCase
{
    public function __construct(
        private readonly ICaravanCastrationRepository $castrationRepository,
        private readonly IAnimalCategoryRepository $categoryRepository
    ) {
    }

    /**
     * @return array{
     *     candidates: list<array{
     *         id: int,
     *         caravan_number: string,
     *         tag_number: ?string,
     *         category_id: ?int,
     *         category_code: ?string,
     *         category_name: ?string,
     *         birth_date: ?string,
     *         blocked_by: ?string
     *     }>,
     *     steer_age_limit_months: int
     * }
     */
    public function __invoke(int $companyId, int $batchId): array
    {
        $candidates = $this->castrationRepository->candidatesByBatch($companyId, $batchId);
        $steerCategory = $this->categoryRepository->findByCode('NOVILLITO');
        $steerAgeLimitMonths = $steerCategory?->getMaxAgeMonths() ?? 20;

        return [
            'candidates' => $candidates,
            'steer_age_limit_months' => $steerAgeLimitMonths,
        ];
    }
}

