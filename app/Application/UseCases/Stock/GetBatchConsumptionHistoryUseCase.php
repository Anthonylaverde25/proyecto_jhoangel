<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Core\Interfaces\IConsumableBatchReader;
use App\Core\Interfaces\IItemDocumentRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * What a batch received and what it cost (logical schema 4.4). The cost per head is taken operation
 * by operation, with the heads declared that day; the accumulated cost per head is their sum, never
 * the total divided by today's heads. Voided operations are shown but do not add up (DS12).
 */
final class GetBatchConsumptionHistoryUseCase
{
    public function __construct(
        private readonly IConsumableBatchReader $batches,
        private readonly IItemDocumentRepository $documents,
    ) {
    }

    /**
     * has_pending_cost: some consumption took units still waiting for their invoice (delivery C); the
     * totals add only what is known.
     *
     * @return array{rows: list<array<string, mixed>>, total_cost: string, cost_per_head: string, by_category: list<array{category: string, cost: string}>, has_pending_cost: bool}
     */
    public function __invoke(int $batchId, int $companyId): array
    {
        $this->batches->find($batchId, $companyId) ?? throw new ModelNotFoundException("Batch {$batchId} not found.");

        $total = '0';
        $perHead = '0';
        $byCategory = [];
        $rows = [];
        $pending = false;
        foreach ($this->documents->batchHistory($batchId, $companyId) as $row) {
            $row['cost_per_head'] = $row['heads_treated'] > 0 ? bcdiv($row['cost'], (string) $row['heads_treated'], 4) : null;
            if ($row['voided'] === null) {
                $pending = $pending || bccomp((string) $row['pending_cost_quantity'], '0', 4) > 0;
                $total = bcadd($total, $row['cost'], 4);
                $perHead = bcadd($perHead, (string) $row['cost_per_head'], 4);
                $category = $row['category'] ?? 'Sin categoría';
                $byCategory[$category] = bcadd($byCategory[$category] ?? '0', $row['cost'], 4);
            }
            $rows[] = $row;
        }
        arsort($byCategory, SORT_NUMERIC);

        return [
            'rows' => array_reverse($rows),
            'total_cost' => $total,
            'cost_per_head' => $perHead,
            'by_category' => array_map(fn (string $name, string $cost) => ['category' => $name, 'cost' => $cost], array_keys($byCategory), $byCategory),
            'has_pending_cost' => $pending,
        ];
    }
}
