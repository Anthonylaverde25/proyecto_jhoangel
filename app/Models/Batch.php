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
}
