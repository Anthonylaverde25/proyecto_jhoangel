<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningOrders;

use App\Application\Services\OCRWeaningOrderResolver;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Interfaces\IWeaningOrderRepository;

/**
 * What the scan uses: the code as read off paper, cleaned the same way the OCR resolver cleans it,
 * so "ds-2026O928-0003" still finds DS-20260928-0003.
 */
final class FindWeaningOrderByCodeUseCase
{
    public function __construct(
        private readonly IWeaningOrderRepository $repository,
        private readonly OCRWeaningOrderResolver $resolver
    ) {
    }

    public function __invoke(string $rawCode, int $companyId): ?WeaningOrderEntity
    {
        $code = $this->resolver->cleanCode($rawCode);

        return $code !== null ? $this->repository->findByCode($code, $companyId) : null;
    }
}
