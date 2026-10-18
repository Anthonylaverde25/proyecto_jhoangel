<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Materialised balance and average cost per (warehouse, item). Written only by the ledger, except
 * critical_stock.
 */
class ItemStock extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'warehouse_id',
        'item_id',
        'quantity',
        'average_unit_cost',
        'critical_stock',
        'last_movement_id',
        'unvalued_quantity',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'warehouse_id' => 'integer',
        'item_id' => 'integer',
        'last_movement_id' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withoutGlobalScopes();
    }
}
