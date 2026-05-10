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
        'type',
        'weighing_date',
    ];

    protected $casts = [
        'batch_id' => 'integer',
        'activity_id' => 'integer',
        'weighing_date' => 'date',
        'weight' => 'decimal:2',
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
