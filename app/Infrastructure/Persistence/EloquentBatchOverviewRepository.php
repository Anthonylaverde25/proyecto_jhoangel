<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Enums\AnimalSex;
use App\Core\Enums\BirthOrderAnimalStatus;
use App\Core\Enums\EntryOrderStatus;
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
            ->where('caravans.batch_id', $batchId)
            ->leftJoin('animal_categories', 'animal_categories.id', '=', 'caravans.category_id')
            ->groupBy('caravans.category_id', 'animal_categories.name')
            ->orderByDesc('total')
            ->get(['caravans.category_id', 'animal_categories.name', DB::raw('COUNT(*) as total')])
            ->map(fn ($row) => [
                'category_id' => $row->category_id !== null ? (int) $row->category_id : null,
                'name' => $row->name,
                'count' => (int) $row->total,
            ])
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
}
