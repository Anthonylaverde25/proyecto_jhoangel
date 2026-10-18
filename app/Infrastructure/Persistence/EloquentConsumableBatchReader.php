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
            ->first(['batches.id', 'batches.name', 'batches.is_active', 'farms.provider_id', 'batch_types.code as type_code']);

        if ($row === null) {
            return null;
        }

        // External = its farm belongs to a provider (same rule as BatchEntity::isExternal).
        return new ConsumableBatch((int) $row->id, (string) $row->name, (bool) $row->is_active, $row->provider_id !== null, $row->type_code);
    }
}
