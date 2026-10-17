<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaravanWeight extends Model
{
    protected $fillable = [
        'caravan_id',
        'weighing_type_id',
        'weighing_order_id',
        'weight',
        'current',
        'weighing_date',
        'weighing_time',
        'method',
        'shrunk_condition',
        'shrink_pct',
        'source',
        'notes',
    ];

    protected $casts = [
        'caravan_id' => 'integer',
        'weighing_type_id' => 'integer',
        'weighing_order_id' => 'integer',
        'weight' => 'decimal:2',
        'shrink_pct' => 'decimal:2',
        'current' => 'boolean',
        'weighing_date' => 'date',
    ];

    /**
     * Get the caravan that owns the weight record.
     */
    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    public function weighingType(): BelongsTo
    {
        return $this->belongsTo(WeighingType::class);
    }
}
