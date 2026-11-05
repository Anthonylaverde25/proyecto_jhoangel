<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Interfaces\IConsumableBatchReader;
use App\Core\ValueObjects\ConsumableBatch;
use Illuminate\Support\Facades\DB;

class EloquentConsumableBatchReader implements IConsumableBatchReader
{
    public function find(int $batchId, int $companyId): ?ConsumableBatch
    {
        $row = DB::table('batches')
            ->leftJoin('farms', 'farms.id', '=', 'batches.farm_id')
            ->leftJoin('batch_types', 'batch_types.id', '=', 'batches.batch_type_id')
            ->where('batches.company_id', $companyId)
            ->where('batches.id', $batchId)
            ->first(['batches.id', 'batches.name', 'batches.is_active', 'batches.caravans_count', 'farms.provider_id', 'batch_types.code as type_code']);

        if ($row === null) {
            return null;
        }

        // External = its farm belongs to a provider (same rule as BatchEntity::isExternal).
        $pending = (int) DB::table('entry_order_pending_heads')->where('batch_id', $batchId)->sum('head_count');

        return new ConsumableBatch((int) $row->id, (string) $row->name, (bool) $row->is_active, $row->provider_id !== null, $row->type_code, (int) $row->caravans_count + $pending);
    }
}
