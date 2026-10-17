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
     * @param list<array{category_id: ?int, name: ?string, count: int}> $categoryComposition
     * @param array{females: int, pregnant: int, without_gestation: int, overdue: int}|null $reproductive
     * @param list<BatchAlert> $alerts sorted by severity
     */
    public function __construct(
        public int $batchId,
        public array $sexComposition,
        public array $categoryComposition,
        public ?\DateTimeInterface $lastWeighingDate,
        public ?array $reproductive,
        public array $alerts,
    ) {
    }
}
