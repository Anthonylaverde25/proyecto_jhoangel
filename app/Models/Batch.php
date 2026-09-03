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
        'min_weight',
        'max_weight',
        'knows_to_eat',
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
        'min_weight' => 'float',
        'max_weight' => 'float',
        'knows_to_eat' => 'boolean',
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

    public function isInQuarantine(): bool
    {
        return $this->batchType?->code === 'QUARANTINE' ?? false;
    }

    public function isServiceBatch(): bool
    {
        return $this->batchType?->code === 'SERVICE' ?? false;
    }

    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }

}
