<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'farm_id',
        'activity_id',
        'current_weight',
        'observaciones',
        'is_active',
        'batch_type_id'
    ];

    protected $casts = [
        'farm_id' => 'integer',
        'activity_id' => 'integer',
        'current_weight' => 'float',
        'is_active' => 'boolean',
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

    public function scopeOperational($query)
    {
        return $query->whereHas('batchType', function ($q) {
            $q->where('code', 'OPERATIONAL');
        });
    }

    public function scopeQuarantine($query)
    {
        return $query->whereHas('type', function ($q) {
            $q->where('code', 'QUARANTINE');
        });
    }

   public function isInQuarantine():bool
   {
    return $this->batchType?->code === 'QUARANTINE' ?? false;
   }

}
