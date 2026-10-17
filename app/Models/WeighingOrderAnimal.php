<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeighingOrderAnimal extends Model
{
    protected $fillable = [
        'company_id', 'weighing_order_id', 'caravan_id', 'source_batch_id', 'weighing_order_group_id',
        'status', 'out_of_order', 'caravan_weight_id', 'weighed_at',
    ];

    protected $casts = [
        'caravan_id' => 'integer',
        'source_batch_id' => 'integer',
        'caravan_weight_id' => 'integer',
        'out_of_order' => 'boolean',
        'weighed_at' => 'datetime',
    ];

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }
}
