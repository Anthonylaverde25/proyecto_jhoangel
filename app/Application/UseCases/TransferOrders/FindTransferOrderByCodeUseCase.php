<?php

declare(strict_types=1);

namespace App\Application\UseCases\TransferOrders;

use App\Application\Services\OCRTransferOrderResolver;
use App\Core\Entities\TransferOrderEntity;
use App\Core\Interfaces\ITransferOrderRepository;

/**
 * What the scan uses: the code as read off paper, cleaned the same way the OCR resolver cleans
 * it, so "tr-2026O922-0014" still finds TR-20260922-0014.
 */
final class FindTransferOrderByCodeUseCase
{
    public function __construct(
        private readonly ITransferOrderRepository $repository,
        private readonly OCRTransferOrderResolver $resolver
    ) {
    }

    public function __invoke(string $rawCode, int $companyId): ?TransferOrderEntity
    {
        $code = $this->resolver->cleanCode($rawCode);

        return $code !== null ? $this->repository->findByCode($code, $companyId) : null;
    }
}
