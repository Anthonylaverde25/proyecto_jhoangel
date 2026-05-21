<?php

declare(strict_types=1);

namespace App\Application\UseCases\BatchTypes;

use App\Core\Interfaces\IBatchTypeRepository;

class ListBatchTypesUseCase
{
    public function __construct(
        private readonly IBatchTypeRepository $batchTypeRepository
    ) {}

    /**
     * List all active batch types for the given company.
     *
     * @param int $companyId
     * @return array<\App\Core\Entities\BatchTypeEntity>
     */
    public function __invoke(int $companyId): array
    {
        return $this->batchTypeRepository->findAllActiveByCompany($companyId);
    }
}
