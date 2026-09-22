<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchWeight extends Model
{
    protected $fillable = [
        'batch_id',
        'activity_id',
        'weight',
        'total_weight',
        'caravans_count',
        'weighed_count',
        'weights_as_of',
        'type',
        'weighing_date',
    ];

    protected $casts = [
        'batch_id' => 'integer',
        'activity_id' => 'integer',
        'weighing_date' => 'date',
        'weights_as_of' => 'date',
        'weight' => 'decimal:2',
        'total_weight' => 'decimal:2',
        'caravans_count' => 'integer',
        'weighed_count' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
