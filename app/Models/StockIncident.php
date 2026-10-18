<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A non-blocking stock notice (D5, D19).
 */
class StockIncident extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'warehouse_id',
        'item_id',
        'stock_movement_id',
        'type',
        'detail',
        'metadata',
        'status',
        'resolution',
        'resolved_by_user_id',
        'resolved_at',
        'raised_by_user_id',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'warehouse_id' => 'integer',
        'item_id' => 'integer',
        'stock_movement_id' => 'integer',
        'metadata' => 'array',
        'resolved_by_user_id' => 'integer',
        'raised_by_user_id' => 'integer',
        'resolved_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withoutGlobalScopes();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withoutGlobalScopes();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
