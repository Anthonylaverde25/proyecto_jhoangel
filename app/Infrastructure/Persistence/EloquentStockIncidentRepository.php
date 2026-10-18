<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\StockMapper;
use App\Core\Entities\StockIncidentEntity;
use App\Core\Enums\StockIncidentStatus;
use App\Core\Enums\StockIncidentType;
use App\Core\Interfaces\IStockIncidentRepository;
use App\Models\StockIncident;
use Illuminate\Database\Eloquent\Builder;

class EloquentStockIncidentRepository implements IStockIncidentRepository
{
    public function findOpen(int $warehouseId, int $itemId, StockIncidentType $type, int $companyId): ?StockIncidentEntity
    {
        $model = $this->query($companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->where('type', $type->value)
            ->where('status', StockIncidentStatus::OPEN->value)
            ->first();

        return $model ? StockMapper::incidentToEntity($model) : null;
    }

    public function save(StockIncidentEntity $incident): StockIncidentEntity
    {
        $model = $incident->getId() !== null
            ? $this->query($incident->getCompanyId())->findOrFail($incident->getId())
            : new StockIncident([
                'company_id' => $incident->getCompanyId(),
                'warehouse_id' => $incident->getWarehouseId(),
                'item_id' => $incident->getItemId(),
                'type' => $incident->getType()->value,
                'raised_by_user_id' => $incident->getRaisedBy(),
            ]);

        $model->fill([
            'stock_movement_id' => $incident->getStockMovementId(),
            'detail' => $incident->getDetail(),
            'metadata' => $incident->getMetadata(),
            'status' => $incident->getStatus()->value,
            'resolution' => $incident->getResolution(),
            'resolved_by_user_id' => $incident->getResolvedBy(),
            'resolved_at' => $incident->getResolvedAt(),
        ]);
        $model->save();

        return StockMapper::incidentToEntity($model->load(['item', 'warehouse', 'resolver']));
    }

    public function search(int $companyId, ?StockIncidentStatus $status, ?int $itemId): array
    {
        return $this->query($companyId)
            ->with(['item', 'warehouse', 'resolver'])
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status->value))
            ->when($itemId !== null, fn (Builder $q) => $q->where('item_id', $itemId))
            ->orderByDesc('id')
            ->get()
            ->map(fn (StockIncident $model) => StockMapper::incidentToEntity($model))
            ->all();
    }

    public function findById(int $id, int $companyId): ?StockIncidentEntity
    {
        $model = $this->query($companyId)->with(['item', 'warehouse', 'resolver'])->find($id);

        return $model ? StockMapper::incidentToEntity($model) : null;
    }

    private function query(int $companyId): Builder
    {
        return StockIncident::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
