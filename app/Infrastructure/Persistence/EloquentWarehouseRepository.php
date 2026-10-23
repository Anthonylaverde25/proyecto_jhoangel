<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\DecimalColumn;
use App\Application\Mappers\StockMapper;
use App\Core\Entities\WarehouseEntity;
use App\Core\Enums\WarehouseKind;
use App\Core\Interfaces\IWarehouseRepository;
use App\Core\ValueObjects\WarehouseSummary;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every query filters by company explicitly (D15, layer 2): the company bootstrap runs without a
 * request context.
 */
class EloquentWarehouseRepository implements IWarehouseRepository
{
    public function findDefault(int $companyId, WarehouseKind $kind = WarehouseKind::GENERAL): ?WarehouseEntity
    {
        $model = $this->query($companyId)->where('kind', $kind->value)->where('is_default', true)->first();

        return $model ? StockMapper::warehouseToEntity($model) : null;
    }

    public function findById(int $id, int $companyId): ?WarehouseEntity
    {
        $model = $this->query($companyId)->find($id);

        return $model ? StockMapper::warehouseToEntity($model) : null;
    }

    public function findAll(int $companyId): array
    {
        return $this->query($companyId)
            ->orderByRaw("CASE WHEN kind = 'GENERAL' THEN 0 ELSE 1 END")
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get()
            ->map(fn (Warehouse $model) => StockMapper::warehouseToEntity($model))
            ->all();
    }

    public function ensureDefault(int $companyId): WarehouseEntity
    {
        $existing = $this->findDefault($companyId);
        if ($existing !== null) {
            return $existing;
        }

        $model = Warehouse::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'code' => WarehouseEntity::DEFAULT_CODE,
            'name' => WarehouseEntity::DEFAULT_NAME,
            'kind' => WarehouseKind::GENERAL->value,
            'is_default' => true,
            'is_active' => true,
        ]);

        return StockMapper::warehouseToEntity($model);
    }

    public function create(int $companyId, string $code, string $name, WarehouseKind $kind): WarehouseEntity
    {
        $model = Warehouse::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'kind' => $kind->value,
            'is_default' => $this->findDefault($companyId, $kind) === null,
            'is_active' => true,
        ]);

        return StockMapper::warehouseToEntity($model);
    }

    public function save(WarehouseEntity $warehouse): WarehouseEntity
    {
        $model = $this->query($warehouse->companyId)->findOrFail($warehouse->id);
        $model->update([
            'code' => $warehouse->code,
            'name' => $warehouse->name,
            'kind' => $warehouse->kind->value,
            'is_default' => $model->is_default && $warehouse->isDefault,
            'is_active' => $warehouse->isActive,
        ]);

        return StockMapper::warehouseToEntity($model);
    }

    public function swapDefault(int $companyId, int $newDefaultId): void
    {
        DB::transaction(function () use ($companyId, $newDefaultId) {
            $kind = $this->query($companyId)->whereKey($newDefaultId)->value('kind');
            // Down first: uq_wh_one_default never sees two defaults of a kind.
            $this->query($companyId)->where('kind', $kind)->where('is_default', true)->where('id', '!=', $newDefaultId)->update(['is_default' => false]);
            $this->query($companyId)->where('id', $newDefaultId)->update(['is_default' => true, 'is_active' => true]);
        });
    }

    public function hasMovements(int $id, int $companyId): bool
    {
        return DB::table('stock_movements')->where('company_id', $companyId)->where('warehouse_id', $id)->exists()
            || DB::table('item_documents')->where('company_id', $companyId)->where('warehouse_id', $id)->exists();
    }

    public function codes(int $companyId): array
    {
        return $this->query($companyId)->pluck('code')->all();
    }

    public function codeTaken(int $companyId, string $code, ?int $exceptId = null): bool
    {
        return $this->query($companyId)->where('code', $code)->when($exceptId !== null, fn (Builder $q) => $q->where('id', '!=', $exceptId))->exists();
    }

    public function nameTaken(int $companyId, string $name, ?int $exceptId = null): bool
    {
        return $this->query($companyId)->where('name', $name)->when($exceptId !== null, fn (Builder $q) => $q->where('id', '!=', $exceptId))->exists();
    }

    public function summaries(int $companyId): array
    {
        $stocks = DB::table('item_stocks')
            ->where('company_id', $companyId)
            ->groupBy('warehouse_id')
            ->selectRaw('warehouse_id,
                SUM(CASE WHEN quantity > 0 THEN 1 ELSE 0 END) AS items_with_balance,
                SUM(CASE WHEN quantity - unvalued_quantity > 0 THEN (quantity - unvalued_quantity) * average_unit_cost ELSE 0 END) AS valued_value,
                SUM(CASE WHEN unvalued_quantity > 0 THEN 1 ELSE 0 END) AS items_with_unvalued,
                SUM(CASE WHEN quantity < 0 THEN 1 ELSE 0 END) AS negative_items')
            ->get()
            ->keyBy('warehouse_id');
        $lots = $this->countBy('stock_unvalued_lots', $companyId, 'status', 'PENDING');
        $incidents = $this->countBy('stock_incidents', $companyId, 'status', 'OPEN');

        $summaries = [];
        foreach ($this->query($companyId)->pluck('id') as $id) {
            $row = $stocks[$id] ?? null;
            $summaries[(int) $id] = new WarehouseSummary(
                (int) ($row->items_with_balance ?? 0),
                DecimalColumn::read($row->valued_value ?? null, 4),
                (int) ($row->items_with_unvalued ?? 0),
                $lots[(int) $id] ?? 0,
                (int) ($row->negative_items ?? 0),
                $incidents[(int) $id] ?? 0,
            );
        }

        return $summaries;
    }

    /** @return array<int, int> */
    private function countBy(string $table, int $companyId, string $column, string $value): array
    {
        return DB::table($table)->where('company_id', $companyId)->where($column, $value)
            ->groupBy('warehouse_id')->selectRaw('warehouse_id, COUNT(*) AS total')
            ->pluck('total', 'warehouse_id')->map(fn ($n) => (int) $n)->all();
    }

    private function query(int $companyId): Builder
    {
        return Warehouse::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
