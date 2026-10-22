<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\WeaningOrderDeviationEntity;
use App\Core\Enums\WeaningDeviationKind;
use App\Core\Interfaces\IWeaningOrderDeviationRepository;
use App\Models\WeaningOrderDeviation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentWeaningOrderDeviationRepository implements IWeaningOrderDeviationRepository
{
    private const RELATIONS = ['weaningOrder', 'caravan', 'line.sourceBatch', 'recordedBy', 'reviewedBy'];

    public function recordMany(array $deviations): void
    {
        if ($deviations === []) {
            return;
        }

        $now = now();
        $rows = array_map(fn (WeaningOrderDeviationEntity $d) => [
            'company_id' => $d->getCompanyId(),
            'weaning_order_id' => $d->getWeaningOrderId(),
            'weaning_order_animal_id' => $d->getWeaningOrderAnimalId(),
            'caravan_id' => $d->getCaravanId(),
            'kind' => $d->getKind()->value,
            'planned' => $d->getPlanned(),
            'actual' => $d->getActual(),
            'metadata' => json_encode($d->getMetadata()),
            'occurred_at' => $d->getOccurredAt(),
            'recorded_by_user_id' => $d->getRecordedByUserId(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $deviations);

        // The unique (line, kind) keeps the first one: a calf is not counted twice for the same thing.
        DB::table('weaning_order_deviations')->insertOrIgnore($rows);
    }

    public function list(
        int $companyId,
        ?bool $reviewed = null,
        ?WeaningDeviationKind $kind = null,
        ?string $orderCode = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        return WeaningOrderDeviation::with(self::RELATIONS)
            ->where('company_id', $companyId)
            ->when($reviewed === true, fn (Builder $q) => $q->whereNotNull('reviewed_at'))
            ->when($reviewed === false, fn (Builder $q) => $q->whereNull('reviewed_at'))
            ->when($kind !== null, fn (Builder $q) => $q->where('kind', $kind->value))
            ->when($orderCode !== null, fn (Builder $q) => $q->whereHas('weaningOrder', fn (Builder $o) => $o->where('code', strtoupper(trim((string) $orderCode)))))
            ->when($from !== null, fn (Builder $q) => $q->whereDate('occurred_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('occurred_at', '<=', $to))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (WeaningOrderDeviation $model) => $this->toEntity($model))
            ->all();
    }

    public function findByIds(array $ids, int $companyId): array
    {
        return WeaningOrderDeviation::with(self::RELATIONS)
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (WeaningOrderDeviation $model) => $this->toEntity($model))
            ->all();
    }

    public function saveReviews(array $deviations): void
    {
        foreach ($deviations as $deviation) {
            WeaningOrderDeviation::whereKey($deviation->getId())
                ->whereNull('reviewed_at')
                ->update([
                    'reviewed_at' => $deviation->getReviewedAt(),
                    'reviewed_by_user_id' => $deviation->getReviewedByUserId(),
                    'review_note' => $deviation->getReviewNote(),
                ]);
        }
    }

    private function toEntity(WeaningOrderDeviation $model): WeaningOrderDeviationEntity
    {
        $sex = $model->caravan?->sex;

        return new WeaningOrderDeviationEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            weaningOrderId: (int) $model->weaning_order_id,
            weaningOrderAnimalId: (int) $model->weaning_order_animal_id,
            caravanId: (int) $model->caravan_id,
            kind: WeaningDeviationKind::from($model->kind),
            planned: $model->planned,
            actual: $model->actual,
            metadata: $model->metadata ?? [],
            occurredAt: $model->occurred_at,
            recordedByUserId: $model->recorded_by_user_id,
            reviewedAt: $model->reviewed_at,
            reviewedByUserId: $model->reviewed_by_user_id,
            reviewNote: $model->review_note,
            orderCode: $model->weaningOrder?->code,
            orderStatus: $model->weaningOrder?->status,
            identification: $model->caravan?->identification,
            sex: $sex instanceof \BackedEnum ? (string) $sex->value : $sex,
            sourceBatchName: $model->line?->sourceBatch?->name,
            recordedByUserName: $model->recordedBy?->name,
            reviewedByUserName: $model->reviewedBy?->name
        );
    }
}
