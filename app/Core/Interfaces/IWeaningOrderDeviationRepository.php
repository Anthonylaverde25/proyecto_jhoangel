<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\WeaningOrderDeviationEntity;
use App\Core\Enums\WeaningDeviationKind;

interface IWeaningOrderDeviationRepository
{
    /**
     * Writes new deviations; one already recorded for the same calf and kind is kept as it was.
     *
     * @param WeaningOrderDeviationEntity[] $deviations
     */
    public function recordMany(array $deviations): void;

    /**
     * @param bool|null $reviewed null = both
     * @return WeaningOrderDeviationEntity[] newest first, with their order and calf
     */
    public function list(
        int $companyId,
        ?bool $reviewed = null,
        ?WeaningDeviationKind $kind = null,
        ?string $orderCode = null,
        ?string $from = null,
        ?string $to = null
    ): array;

    /**
     * @param list<int> $ids
     * @return WeaningOrderDeviationEntity[]
     */
    public function findByIds(array $ids, int $companyId): array;

    /**
     * Stores the review of each deviation.
     *
     * @param WeaningOrderDeviationEntity[] $deviations
     */
    public function saveReviews(array $deviations): void;
}
