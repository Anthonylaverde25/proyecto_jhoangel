<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Enums\GestationStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'success',
        'loss_reason_id',
        'loss_notes',
        'end_date',
        'notes',
        'service_order_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'caravan_id' => 'integer',
        'start_date' => 'date',
        'estimated_due_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
        'success' => 'boolean',
        'loss_reason_id' => 'integer',
        'gestation_stage' => GestationStage::class,
        'gestation_months' => 'float',
        'service_order_id' => 'integer',
    ];

    /**
     * Get the service order associated with this gestation.
     */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    /**
     * Get the caravan (mother) that owns the gestation.
     */
    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    /**
     * Get the potential fathers (sires) for this gestation.
     */
    public function sires(): BelongsToMany
    {
        return $this->belongsToMany(Caravan::class, 'gestation_sires', 'gestation_id', 'sire_id')
            ->withPivot('is_confirmed')
            ->withTimestamps();
    }

    /**
     * Get the gestation loss reason.
     */
    public function lossReason(): BelongsTo
    {
        return $this->belongsTo(GestationLossReason::class, 'loss_reason_id');
    }

    /**
     * Get the offspring lineage born from this gestation.
     */
    public function offspring(): HasMany
    {
        return $this->hasMany(CaravanLineage::class, 'gestation_id');
    }
}

