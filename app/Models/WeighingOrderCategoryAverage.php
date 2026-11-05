<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeighingOrderCategoryAverage extends Model
{
    protected $fillable = [
        'company_id', 'weighing_order_id', 'category_id', 'head_count', 'average_weight',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'head_count' => 'integer',
        'average_weight' => 'decimal:2',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(AnimalCategory::class, 'category_id');
    }
}
