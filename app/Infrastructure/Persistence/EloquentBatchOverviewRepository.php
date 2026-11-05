<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Enums\AnimalSex;
use App\Core\Enums\BirthOrderAnimalStatus;
use App\Core\Enums\EntryOrderStatus;
use App\Core\Enums\ServiceOrderMaleStatus;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Enums\TransferOrderStatus;
use App\Core\Interfaces\IBatchOverviewRepository;
use App\Models\Caravan;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\DB;

class EloquentBatchOverviewRepository implements IBatchOverviewRepository
{
    private const OPEN_ORDER_STATUSES = [TransferOrderStatus::ISSUED->value, TransferOrderStatus::PARTIAL->value];

    public function sexComposition(int $batchId): array
    {
        $counts = Caravan::inPossession()
            ->resident()
            ->where('batch_id', $batchId)
            ->selectRaw('sex, COUNT(*) as total')
            ->groupBy('sex')
            ->pluck('total', 'sex');

        return [
            AnimalSex::MALE->value => (int) ($counts[AnimalSex::MALE->value] ?? 0),
            AnimalSex::FEMALE->value => (int) ($counts[AnimalSex::FEMALE->value] ?? 0),
        ];
    }

    public function categoryComposition(int $batchId): array
    {
        return Caravan::inPossession()
            ->resident()
            ->where('caravans.batch_id', $batchId)
            ->leftJoin('animal_categories', 'animal_categories.id', '=', 'caravans.category_id')
            ->leftJoin('caravan_weights', function ($join) {
                $join->on('caravan_weights.caravan_id', '=', 'caravans.id')->where('caravan_weights.current', true);
            })
            ->groupBy('caravans.category_id', 'animal_categories.name')
            ->orderByDesc('total')
            ->get([
                'caravans.category_id',
                'animal_categories.name',
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(caravan_weights.id) as weighed'),
                DB::raw('AVG(caravan_weights.weight) as average'),
            ])
            ->map(fn ($row) => [
                'category_id' => $row->category_id !== null ? (int) $row->category_id : null,
                'name' => $row->name,
                'count' => (int) $row->total,
                'weighed_count' => (int) $row->weighed,
                'average_weight' => $row->average !== null ? round((float) $row->average, 2) : null,
            ])
            ->values()
            ->all();
    }

    public function categoryWeightReadings(int $batchId): array
    {
        return Caravan::inPossession()
            ->resident()
            ->where('caravans.batch_id', $batchId)
            ->join('caravan_weights', 'caravan_weights.caravan_id', '=', 'caravans.id')
            ->leftJoin('animal_categories', 'animal_categories.id', '=', 'caravans.category_id')
            ->orderBy('caravan_weights.weighing_date')
            ->orderBy('caravan_weights.id')
            ->get([
                'caravans.id as caravan_id',
                'caravans.category_id',
                'animal_categories.name as category_name',
                'caravan_weights.weight',
                'caravan_weights.weighing_date',
            ])
            ->map(fn ($row) => [
                'caravan_id' => (int) $row->caravan_id,
                'category_id' => $row->category_id !== null ? (int) $row->category_id : null,
                'category_name' => $row->category_name,
                'weight' => (float) $row->weight,
                'date' => substr((string) $row->weighing_date, 0, 10),
            ])
            ->values()
            ->all();
    }

    public function bullsOnService(int $batchId): array
    {
        return Caravan::inPossession()
            ->onServiceInItsBatch()
            ->where('caravans.batch_id', $batchId)
            ->join('service_order_males', 'service_order_males.male_caravan_id', '=', 'caravans.id')
            ->join('service_orders', function ($join) {
                $join->on('service_orders.id', '=', 'service_order_males.service_order_id')
                    ->on('service_orders.batch_id', '=', 'caravans.batch_id')
                    ->where('service_orders.status', ServiceOrderStatus::APPROVED->value);
            })
            ->where('service_order_males.status', ServiceOrderMaleStatus::ACTIVE->value)
            ->leftJoin('animal_categories', 'animal_categories.id', '=', 'caravans.category_id')
            ->leftJoin('caravan_weights', function ($join) {
                $join->on('caravan_weights.caravan_id', '=', 'caravans.id')->where('caravan_weights.current', true);
            })
            ->orderBy('caravans.identification')
            ->get([
                'caravans.id',
                'caravans.identification',
                'animal_categories.name as category_name',
                'caravan_weights.weight',
                'service_orders.id as order_id',
                'service_orders.code as order_code',
                'service_order_males.joined_at',
            ])
            ->map(function ($row) use ($batchId) {
                // Where he came from, the batch he normally goes back to.
                $origin = DB::table('caravan_movements as m')
                    ->leftJoin('batches as b', 'b.id', '=', 'm.from_batch_id')
                    ->where('m.caravan_id', $row->id)
                    ->where('m.to_batch_id', $batchId)
                    ->orderByDesc('m.movement_date')
                    ->orderByDesc('m.id')
                    ->first(['m.from_batch_id', 'b.name']);

                return [
                    'caravan_id' => (int) $row->id,
                    'identification' => $row->identification !== null ? (string) $row->identification : null,
                    'category_name' => $row->category_name,
                    'weight' => $row->weight !== null ? (float) $row->weight : null,
                    'order_id' => (int) $row->order_id,
                    'order_code' => (string) $row->order_code,
                    'joined_at' => $row->joined_at !== null ? substr((string) $row->joined_at, 0, 10) : null,
                    'origin_batch_id' => $origin?->from_batch_id !== null ? (int) $origin->from_batch_id : null,
                    'origin_batch_name' => $origin?->name,
                ];
            })
            ->values()
            ->all();
    }

    public function pregnancyCounts(int $batchId): array
    {
        $females = Caravan::inPossession()
            ->where('batch_id', $batchId)
            ->where('sex', AnimalSex::FEMALE->value);

        return [
            'females' => (clone $females)->count(),
            'pregnant' => (clone $females)->whereHas('gestations', fn ($q) => $q->where('is_current', true))->count(),
        ];
    }

    public function overdueBirths(int $batchId, int $companyId): array
    {
        return DB::table('birth_order_animals as a')
            ->join('birth_orders as o', 'o.id', '=', 'a.birth_order_id')
            ->join('caravans as c', 'c.id', '=', 'a.mother_caravan_id')
            ->where('a.company_id', $companyId)
            ->where('a.status', BirthOrderAnimalStatus::OVERDUE->value)
            ->where('o.status', '!=', TransferOrderStatus::CANCELLED->value)
            ->where('c.batch_id', $batchId)
            ->groupBy('o.id', 'o.code')
            ->orderBy('o.id')
            ->get(['o.id', 'o.code', DB::raw('COUNT(*) as total')])
            ->map(fn ($row) => ['birth_order_id' => (int) $row->id, 'code' => (string) $row->code, 'count' => (int) $row->total])
            ->all();
    }

    public function openEntryIncidents(int $batchId, int $companyId): array
    {
        // The orders that send animals to the batch: by a destination rule, by head received
        // there awaiting their caravan, or as the batch of the order itself.
        $orderIds = DB::table('entry_order_destinations')
            ->where('company_id', $companyId)
            ->where('destination_batch_id', $batchId)
            ->select('entry_order_id')
            ->union(
                DB::table('entry_order_pending_heads')
                    ->where('company_id', $companyId)
                    ->where('batch_id', $batchId)
                    ->select('entry_order_id')
            )
            ->union(
                DB::table('entry_orders')
                    ->where('company_id', $companyId)
                    ->where('batch_id', $batchId)
                    ->select('id as entry_order_id')
            );

        return DB::table('entry_order_incidents as i')
            ->join('entry_orders as o', 'o.id', '=', 'i.entry_order_id')
            ->where('i.company_id', $companyId)
            ->where('i.status', 'OPEN')
            ->where('o.status', '!=', EntryOrderStatus::CANCELLED->value)
            ->whereIn('i.entry_order_id', $orderIds)
            ->groupBy('o.id', 'o.code')
            ->orderBy('o.id')
            ->get(['o.id', 'o.code', DB::raw('COUNT(*) as total')])
            ->map(fn ($row) => ['entry_order_id' => (int) $row->id, 'code' => (string) $row->code, 'count' => (int) $row->total])
            ->all();
    }

    public function pendingOrders(int $batchId, int $companyId): array
    {
        $sources = [
            'transfer_order' => ['transfer_orders', 'transfer_order_animals', 'transfer_order_id', 'caravan_id'],
            'weaning_order' => ['weaning_orders', 'weaning_order_animals', 'weaning_order_id', 'caravan_id'],
            'birth_order' => ['birth_orders', 'birth_order_animals', 'birth_order_id', 'mother_caravan_id'],
        ];

        $orders = [];
        foreach ($sources as $kind => [$orderTable, $lineTable, $orderKey, $caravanKey]) {
            $rows = DB::table("{$lineTable} as l")
                ->join("{$orderTable} as o", 'o.id', '=', "l.{$orderKey}")
                ->join('caravans as c', 'c.id', '=', "l.{$caravanKey}")
                ->where('o.company_id', $companyId)
                ->whereIn('o.status', self::OPEN_ORDER_STATUSES)
                ->where('l.status', 'PENDING')
                ->where('c.batch_id', $batchId)
                ->groupBy('o.id', 'o.code')
                ->orderBy('o.id')
                ->get(['o.id', 'o.code', DB::raw('COUNT(*) as total')]);

            foreach ($rows as $row) {
                $orders[] = ['kind' => $kind, 'id' => (int) $row->id, 'code' => (string) $row->code, 'pending_count' => (int) $row->total];
            }
        }

        return $orders;
    }

    public function activeServiceOrder(int $batchId): ?array
    {
        $order = ServiceOrder::where('batch_id', $batchId)
            ->whereIn('status', ['DRAFT', 'APPROVED'])
            ->first(['id', 'code', 'status', 'planned_start_date']);

        return $order === null ? null : [
            'id' => (int) $order->id,
            'code' => (string) $order->code,
            'status' => (string) $order->status,
            'planned_start_date' => $order->planned_start_date?->format('Y-m-d'),
        ];
    }

    public function castrationCandidateCount(int $batchId, int $companyId): int
    {
        return Caravan::withoutGlobalScopes()
            ->where('caravans.company_id', $companyId)
            ->where('caravans.batch_id', $batchId)
            ->where('caravans.sex', 'M')
            ->whereDoesntHave('death')
            ->whereDoesntHave('exitLine')
            ->whereHas('category', function ($q) {
                $q->whereIn('code', ['TERNERO', 'TORITO']);
            })
            ->whereDoesntHave('castrations', function ($q) {
                $q->whereNull('voided_at');
            })
            ->count();
    }
}
