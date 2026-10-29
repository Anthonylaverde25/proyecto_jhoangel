<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Enums\ExitOrderAnimalStatus;
use App\Core\Interfaces\IExitedAnimalReader;
use App\Core\ValueObjects\AnimalLabel;
use App\Core\ValueObjects\ExitedAnimalCriteria;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** One query over the exit lines; every join is pinned to the company explicitly (D15, layer 2). */
class EloquentExitedAnimalReader implements IExitedAnimalReader
{
    public function exited(ExitedAnimalCriteria $criteria): array
    {
        $company = $criteria->companyId;

        return DB::table('exit_order_animals as l')
            ->join('exit_orders as o', fn ($join) => $join->on('o.id', '=', 'l.exit_order_id')->where('o.company_id', $company))
            ->join('caravans as c', 'c.id', '=', 'l.caravan_id')
            ->leftJoin('caravan_lineage as lin', 'lin.caravan_id', '=', 'c.id')
            ->leftJoin('caravans as m', 'm.id', '=', 'lin.mother_id')
            ->leftJoin('partners as p', fn ($join) => $join->on('p.id', '=', 'o.buyer_partner_id')->where('p.company_id', $company))
            ->leftJoin('batches as b', fn ($join) => $join->on('b.id', '=', 'l.from_batch_id')->where('b.company_id', $company))
            ->leftJoin('activities as a', 'a.id', '=', 'l.activity_id')
            ->leftJoin('animal_categories as cat', 'cat.id', '=', 'l.category_id')
            ->where('l.company_id', $company)
            ->where('l.status', ExitOrderAnimalStatus::EXITED->value)
            ->when($criteria->from !== null, fn (Builder $q) => $q->whereDate('l.exited_at', '>=', $criteria->from))
            ->when($criteria->to !== null, fn (Builder $q) => $q->whereDate('l.exited_at', '<=', $criteria->to))
            ->when($criteria->exitOrderId !== null, fn (Builder $q) => $q->where('l.exit_order_id', $criteria->exitOrderId))
            ->when($criteria->buyerPartnerId !== null, fn (Builder $q) => $q->where('o.buyer_partner_id', $criteria->buyerPartnerId))
            ->when($criteria->categoryId !== null, fn (Builder $q) => $q->where('l.category_id', $criteria->categoryId))
            ->when($criteria->activityId !== null, fn (Builder $q) => $q->where('l.activity_id', $criteria->activityId))
            ->when($criteria->fromBatchId !== null, fn (Builder $q) => $q->where('l.from_batch_id', $criteria->fromBatchId))
            ->orderByDesc('l.exited_at')->orderByDesc('o.id')->orderBy('l.id')
            ->get([
                'l.id', 'l.caravan_id', 'l.was_pregnant', 'l.weight_kg', 'l.weight_estimated', 'l.price_per_kg', 'l.amount', 'l.exited_at',
                'o.id as order_id', 'o.code as order_code', 'o.kind as order_kind', 'o.reason as order_reason',
                'c.identification', 'c.sex', 'm.identification as mother_identification',
                'p.id as buyer_id', 'p.name as buyer_name', 'p.cuit as buyer_cuit',
                'b.id as batch_id', 'b.name as batch_name',
                'a.id as activity_id', 'a.name as activity_name',
                'cat.id as category_id', 'cat.name as category_name',
            ])
            ->map(fn (object $row) => $this->row($row))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(object $row): array
    {
        $ref = fn (mixed $id, mixed $name) => $id !== null ? ['id' => (int) $id, 'name' => $name] : null;
        $decimal = fn (mixed $value) => $value !== null ? (float) $value : null;
        $identification = $row->identification !== null ? (string) $row->identification : null;

        return [
            'id' => (int) $row->id,
            'exit_order' => [
                'id' => (int) $row->order_id,
                'code' => $row->order_code,
                'kind' => $row->order_kind,
                'reason' => $row->order_reason,
            ],
            'exit_date' => substr((string) $row->exited_at, 0, 10),
            'buyer' => $row->buyer_id !== null
                ? ['id' => (int) $row->buyer_id, 'name' => $row->buyer_name, 'cuit' => $row->buyer_cuit]
                : null,
            'caravan_id' => (int) $row->caravan_id,
            'label' => AnimalLabel::of(
                $identification,
                $row->mother_identification !== null ? (string) $row->mother_identification : null,
                $row->sex,
            ),
            'sex' => $row->sex,
            'from_batch' => $ref($row->batch_id, $row->batch_name),
            'activity' => $ref($row->activity_id, $row->activity_name),
            'category' => $ref($row->category_id, $row->category_name),
            'was_pregnant' => $row->was_pregnant !== null ? (bool) $row->was_pregnant : null,
            'weight_kg' => $decimal($row->weight_kg),
            'weight_estimated' => (bool) $row->weight_estimated,
            'price_per_kg' => $decimal($row->price_per_kg),
            'amount' => $decimal($row->amount),
        ];
    }
}
