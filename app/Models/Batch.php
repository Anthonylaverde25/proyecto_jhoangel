<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Batch extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'farm_id',
        'activity_id',
        'current_weight',
        'total_weight',
        'caravans_count',
        'weighed_count',
        'min_weight',
        'max_weight',
        'knows_to_eat',
        'is_confined',
        'age_in_months',
        'observaciones',
        'is_active',
        'is_system',
        'batch_type_id'
    ];

    protected $casts = [
        'farm_id' => 'integer',
        'activity_id' => 'integer',
        'current_weight' => 'float',
        'total_weight' => 'float',
        'caravans_count' => 'integer',
        'weighed_count' => 'integer',
        'min_weight' => 'float',
        'max_weight' => 'float',
        'knows_to_eat' => 'boolean',
        'is_confined' => 'boolean',
        'age_in_months' => 'integer',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function caravans(): HasMany
    {
        return $this->hasMany(Caravan::class);
    }

    public function batchType(): BelongsTo
    {
        return $this->belongsTo(BatchType::class);
    }

    /**
     * Movements that took animals OUT of this batch. Distinguishes a batch that was
     * emptied by a transfer from one that never held animals at all.
     */
    public function outgoingMovements(): HasMany
    {
        return $this->hasMany(CaravanMovement::class, 'from_batch_id');
    }

    public function serviceDetail(): HasOne
    {
        return $this->hasOne(ServiceBatchDetail::class, 'batch_id');
    }

    public function scopeOperational($query)
    {
        return $query->whereHas('batchType', function ($q) {
            $q->where('code', 'OPERATIONAL');
        });
    }

    public function scopeQuarantine($query)
    {
        return $query->whereHas('batchType', function ($q) {
            $q->where('code', 'QUARANTINE');
        });
    }

    public function scopeReserve($query)
    {
        return $query->whereHas('batchType', function ($q) {
            $q->where('code', 'RESERVE');
        });
    }

    public function scopeService($query)
    {
        return $query->whereHas('batchType', function ($q) {
            $q->where('code', 'SERVICE');
        });
    }

    public function scopeWeaning($query)
    {
        return $query->whereHas('batchType', function ($q) {
            $q->where('code', 'WEANING');
        });
    }

    public function isInQuarantine(): bool
    {
        return $this->batchType?->code === 'QUARANTINE' ?? false;
    }

    public function isServiceBatch(): bool
    {
        return $this->batchType?->code === 'SERVICE' ?? false;
    }

    public function isWeaningBatch(): bool
    {
        return $this->batchType?->code === 'WEANING' ?? false;
    }

    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }

    /**
     * Campañas de servicio originadas desde este lote base (1 a N).
     */
    public function serviceOrdersAsOrigin(): HasMany
    {
        return $this->hasMany(ServiceOrder::class, 'origin_batch_id')
            ->orderByDesc('planned_start_date');
    }

    /**
     * Orden de servicio activa originada sobre este lote (si existe).
     */
    public function activeServiceOrderAsOrigin(): HasOne
    {
        return $this->hasOne(ServiceOrder::class, 'origin_batch_id')
            ->where('status', 'APPROVED');
    }

    /**
     * Orden de servicio a la que pertenece este lote (si es un lote de servicio).
     */
    public function serviceOrderAsServiceBatch(): HasOne
    {
        return $this->hasOne(ServiceOrder::class, 'service_batch_id');
    }

    /**
     * Helper para verificar si este lote de origen tiene un servicio activo.
     */
    public function isInService(): bool
    {
        return $this->activeServiceOrderAsOrigin()->exists();
    }
}

