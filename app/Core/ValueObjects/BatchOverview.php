<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * What the batch page shows above its caravans: composition, reproductive state and alerts.
 *
 * Head, weights and the running service already travel with the batch itself; this read model
 * only carries what the batch list does not compute. `reproductive` is null outside Cría.
 */
final readonly class BatchOverview
{
    /**
     * @param array{M: int, H: int} $sexComposition
     * @param list<array{category_id: ?int, name: ?string, count: int, weighed_count: int, average_weight: ?float}> $categoryComposition
     * @param array{females: int, pregnant: int, without_gestation: int, overdue: int}|null $reproductive
     * @param list<BatchAlert> $alerts sorted by severity
     * @param list<array{caravan_id: int, identification: ?string, category_name: ?string, weight: ?float, order_id: int, order_code: string, joined_at: ?string, origin_batch_id: ?int, origin_batch_name: ?string}> $bullsOnService
     */
    public function __construct(
        public int $batchId,
        public array $sexComposition,
        public array $categoryComposition,
        public ?\DateTimeInterface $lastWeighingDate,
        public ?array $reproductive,
        public array $alerts,
        public int $castrationCandidateCount = 0,
        public array $bullsOnService = [],
    ) {
    }
}
