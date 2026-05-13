<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaravanWeight extends Model
{
    protected $fillable = [
        'caravan_id',
        'weight',
        'current',
        'weighing_date',
        'notes',
    ];

    protected $casts = [
        'caravan_id' => 'integer',
        'weight' => 'decimal:2',
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
}
