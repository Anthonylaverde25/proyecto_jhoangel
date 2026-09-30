<?php

declare(strict_types=1);

namespace App\Application\UseCases\BirthOrders;

use App\Application\Services\OCRBirthOrderResolver;
use App\Core\Entities\BirthOrderEntity;
use App\Core\Interfaces\IBirthOrderRepository;

/**
 * What the scan uses: the code as read off paper, cleaned the same way the OCR resolver cleans it,
 * so "pa-2026O929-0003" still finds PA-20260929-0003.
 */
final class FindBirthOrderByCodeUseCase
{
    public function __construct(
        private readonly IBirthOrderRepository $repository,
        private readonly OCRBirthOrderResolver $resolver
    ) {
    }

    public function __invoke(string $rawCode, int $companyId): ?BirthOrderEntity
    {
        $code = $this->resolver->cleanCode($rawCode);

        return $code !== null ? $this->repository->findByCode($code, $companyId) : null;
    }
}
