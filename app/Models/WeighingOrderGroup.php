<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeighingOrderGroup extends Model
{
    protected $fillable = [
        'company_id', 'weighing_order_id', 'category_id', 'sequence', 'head_count', 'gross_weight', 'tare_weight', 'weighed_at',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'sequence' => 'integer',
        'head_count' => 'integer',
        'gross_weight' => 'decimal:2',
        'tare_weight' => 'decimal:2',
        'weighed_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(AnimalCategory::class, 'category_id');
    }
}
