<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Enums\GestationResult;
use App\Core\Enums\GestationStage;
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
        'gestation_stage',
        'gestation_months',
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
        'gestation_stage' => GestationStage::class,
        'gestation_months' => 'float',
    ];

    /**
     * Get the caravan that owns the gestation.
     */
    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }
}
