<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeighingOrderGroup extends Model
{
    protected $fillable = [
        'company_id', 'weighing_order_id', 'sequence', 'head_count', 'gross_weight', 'tare_weight', 'weighed_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'head_count' => 'integer',
        'gross_weight' => 'decimal:2',
        'tare_weight' => 'decimal:2',
        'weighed_at' => 'datetime',
    ];
}
