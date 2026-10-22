<?php

declare(strict_types=1);

namespace App\Application\UseCases\WeaningDeviations;

use App\Core\Entities\WeaningOrderDeviationEntity;
use App\Core\Enums\WeaningDeviationKind;
use App\Core\Interfaces\IWeaningOrderDeviationRepository;

/**
 * What the weanings did differently from their orders: the audit tab of "Crías al pie y destete".
 */
final class ListWeaningDeviationsUseCase
{
    public function __construct(private readonly IWeaningOrderDeviationRepository $repository)
    {
    }

    /**
     * @return WeaningOrderDeviationEntity[]
     */
    public function __invoke(
        int $companyId,
        ?bool $reviewed = null,
        ?WeaningDeviationKind $kind = null,
        ?string $orderCode = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        return $this->repository->list($companyId, $reviewed, $kind, $orderCode, $from, $to);
    }
}
