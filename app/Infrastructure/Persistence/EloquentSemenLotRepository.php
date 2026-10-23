<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\DecimalColumn;
use App\Application\Mappers\SemenMapper;
use App\Core\Entities\SemenLotEntity;
use App\Core\Interfaces\ISemenLotRepository;
use App\Models\SemenLot;
use App\Models\SemenLotStock;
use Illuminate\Database\Eloquent\Builder;

/** Every query filters by company explicitly (D15, layer 2). */
class EloquentSemenLotRepository implements ISemenLotRepository
{
    public function findById(int $id, int $companyId): ?SemenLotEntity
    {
        $model = $this->query($companyId)->find($id);

        return $model ? SemenMapper::lotToEntity($model) : null;
    }

    public function findByCode(int $itemId, string $code, int $companyId): ?SemenLotEntity
    {
        $model = $this->query($companyId)->where('item_id', $itemId)->where('code', $code)->first();

        return $model ? SemenMapper::lotToEntity($model) : null;
    }

    public function create(SemenLotEntity $lot): SemenLotEntity
    {
        $model = SemenLot::withoutGlobalScopes()->create([
            'company_id' => $lot->companyId,
            'item_id' => $lot->itemId,
            'code' => $lot->code,
            'collection_date' => $lot->collectionDate,
            'collection_code' => $lot->collectionCode,
            'notes' => $lot->notes,
        ]);

        return SemenMapper::lotToEntity($model);
    }

    public function lockBalance(int $lotId, int $warehouseId, int $companyId): string
    {
        $row = SemenLotStock::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('semen_lot_id', $lotId)->where('warehouse_id', $warehouseId)
            ->lockForUpdate()->first();
        if ($row === null) {
            $row = SemenLotStock::withoutGlobalScopes()->create([
                'company_id' => $companyId, 'semen_lot_id' => $lotId, 'warehouse_id' => $warehouseId, 'quantity' => '0',
            ]);
        }

        return DecimalColumn::read($row->quantity ?? '0', 4);
    }

    public function setBalance(int $lotId, int $warehouseId, int $companyId, string $quantity): void
    {
        SemenLotStock::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('semen_lot_id', $lotId)->where('warehouse_id', $warehouseId)
            ->update(['quantity' => $quantity]);
    }

    public function lotsOfItem(int $itemId, int $companyId): array
    {
        return $this->query($companyId)
            ->with('stocks')
            ->where('item_id', $itemId)
            ->orderBy('code')
            ->get()
            ->map(fn (SemenLot $model) => [
                'lot' => SemenMapper::lotToEntity($model),
                'balances' => $model->stocks->mapWithKeys(fn (SemenLotStock $s) => [(int) $s->warehouse_id => DecimalColumn::read($s->quantity, 4)])->all(),
            ])
            ->all();
    }

    public function totalsByWarehouse(int $itemId, int $companyId): array
    {
        return SemenLotStock::withoutGlobalScopes()
            ->join('semen_lots', 'semen_lots.id', '=', 'semen_lot_stocks.semen_lot_id')
            ->where('semen_lot_stocks.company_id', $companyId)
            ->where('semen_lots.item_id', $itemId)
            ->groupBy('semen_lot_stocks.warehouse_id')
            ->selectRaw('semen_lot_stocks.warehouse_id, SUM(semen_lot_stocks.quantity) AS total')
            ->pluck('total', 'warehouse_id')
            ->map(fn ($total) => DecimalColumn::read($total, 4))
            ->all();
    }

    private function query(int $companyId): Builder
    {
        return SemenLot::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
