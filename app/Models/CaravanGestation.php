<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Enums\GestationResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaravanGestation extends Model
{
    /**
     * @var string[]
     */
    protected $fillable = [
        'caravan_id',
        'start_date',
        'estimated_due_date',
        'is_current',
        'result',
        'end_date',
        'notes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'start_date' => 'date',
        'estimated_due_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
        'result' => GestationResult::class,
    ];

    /**
     * Get the caravan that owns the gestation.
     */
    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }
}
