<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SemenLotIdentification extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'semen_unidentified_entry_id',
        'semen_lot_id',
        'quantity',
        'identified_by',
        'voided_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'semen_unidentified_entry_id' => 'integer',
        'semen_lot_id' => 'integer',
        'identified_by' => 'integer',
        'voided_at' => 'datetime',
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(SemenLot::class, 'semen_lot_id');
    }
}
