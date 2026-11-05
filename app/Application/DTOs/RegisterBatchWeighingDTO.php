<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Core\Enums\BatchWeighingEntry;
use App\Core\Enums\ShrunkCondition;
use App\Core\Enums\WeighingTypeCode;
use App\Core\Exceptions\WeighingOrderDomainException;
use App\Core\ValueObjects\BatchWeighingMeasure;
use App\Core\ValueObjects\CageGroupWeighing;
use App\Core\ValueObjects\CategoryAverageWeighing;

final readonly class RegisterBatchWeighingDTO
{
    public function __construct(
        public int $companyId,
        public ?int $userId,
        public int $batchId,
        public WeighingTypeCode $type,
        public string $weighingDate,
        public ?string $weighingTime,
        public ShrunkCondition $shrunkCondition,
        public ?float $shrinkPct,
        public ?string $responsable,
        public ?string $observations,
        public BatchWeighingMeasure $measure,
    ) {
    }

    /**
     * @param array<string, mixed> $data validated request
     *
     * @throws WeighingOrderDomainException
     */
    public static function fromArray(array $data, int $companyId, ?int $userId): self
    {
        $type = WeighingTypeCode::from((string) $data['weighing_type']);

        return new self(
            companyId: $companyId,
            userId: $userId,
            batchId: (int) $data['batch_id'],
            type: $type,
            weighingDate: (string) $data['weighing_date'],
            weighingTime: $data['weighing_time'] ?? null,
            shrunkCondition: isset($data['shrunk_condition'])
                ? ShrunkCondition::from((string) $data['shrunk_condition'])
                : $type->defaultShrunkCondition(),
            shrinkPct: isset($data['shrink_pct']) ? (float) $data['shrink_pct'] : null,
            responsable: $data['responsable'] ?? null,
            observations: $data['observations'] ?? null,
            measure: self::measure($data),
        );
    }

    /** @param array<string, mixed> $data */
    private static function measure(array $data): BatchWeighingMeasure
    {
        if (BatchWeighingEntry::from((string) $data['entry']) === BatchWeighingEntry::BY_CATEGORY) {
            return BatchWeighingMeasure::fromCategoryAverages(array_map(
                static fn (array $average): CategoryAverageWeighing => new CategoryAverageWeighing(
                    isset($average['category_id']) ? (int) $average['category_id'] : null,
                    (int) $average['head_count'],
                    (float) $average['average_weight'],
                ),
                array_values($data['averages'])
            ));
        }

        $loads = [];
        foreach (array_values($data['groups']) as $index => $group) {
            $loads[] = new CageGroupWeighing(
                $index + 1,
                (int) $group['head_count'],
                (float) $group['gross_weight'],
                (float) ($group['tare_weight'] ?? 0),
                isset($group['category_id']) ? (int) $group['category_id'] : null,
            );
        }

        return BatchWeighingMeasure::fromLoads($loads);
    }
}
