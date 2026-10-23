<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SemenLotStock extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'semen_lot_id',
        'warehouse_id',
        'quantity',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'semen_lot_id' => 'integer',
        'warehouse_id' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
